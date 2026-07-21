<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MlmSimulationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Simulatore compensi MLM in admin (2026-07-21, richiesta di Laura: "un modo
 * semplice per calcolare i bonus e verificare che funzionino").
 *
 * Pagina /admin/mlm-simulatore con due scenari:
 *  - "Simula ricarica cliente": punti (/12 + frazionari), base Prov K e
 *    delta commissioni mensili dirette/indirette;
 *  - "Simula evento BasiQ": cascata bonus di struttura sulla upline, con la
 *    spiegazione di ogni anello.
 *
 * Tutto il calcolo avviene in MlmSimulationService coi motori di produzione
 * dentro una transazione SEMPRE annullata: la pagina non scrive mai nulla
 * nel database, si puo' usare liberamente anche in produzione.
 */
class MlmSimulatorController extends Controller
{
    use AuthorizesBackoffice;

    private const MAX_LISTED_USERS = 30;

    public function show(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        return $this->renderPage($request);
    }

    public function simulateDeposit(Request $request, MlmSimulationService $simulator): View
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'amount_eur' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
        ], [], ['client_id' => 'cliente', 'amount_eur' => 'importo']);

        $client = User::findOrFail($validated['client_id']);

        if ($client->mlm_role !== 'cliente' || ! $client->mlm_client_agent_id) {
            return $this->renderPage($request, depositError: 'L\'utente selezionato non e\' un cliente MLM con un agente assegnato: la ricarica non genererebbe punti ne\' commissioni.');
        }

        $amountEurCents = (int) round(((float) $validated['amount_eur']) * 100);

        $result = $simulator->simulateDeposit($client, $amountEurCents);

        return $this->renderPage($request, depositResult: [
            'client' => $client,
            'agent' => $client->mlmClientAgent,
            'amount_eur_cents' => $amountEurCents,
        ] + $result);
    }

    public function simulateBasiq(Request $request, MlmSimulationService $simulator): View
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'agent_id' => ['required', 'integer', 'exists:users,id'],
        ], [], ['agent_id' => 'agente']);

        $agent = User::findOrFail($validated['agent_id']);

        if ($agent->mlm_role !== 'agente') {
            return $this->renderPage($request, basiqError: 'L\'utente selezionato non e\' un agente MLM: l\'evento BasiQ vale solo per gli agenti.');
        }

        $result = $simulator->simulateBasiq($agent);

        return $this->renderPage($request, basiqResult: ['agent' => $agent] + $result);
    }

    private function renderPage(
        Request $request,
        ?array $depositResult = null,
        ?array $basiqResult = null,
        ?string $depositError = null,
        ?string $basiqError = null,
    ): View {
        $search = trim((string) $request->input('q', ''));

        $applySearch = fn ($query) => $query->when($search !== '', fn ($q) => $q->where(fn ($qq) => $qq
            ->where('name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%")));

        $clients = $applySearch(
            User::query()
                ->where('mlm_role', 'cliente')
                ->whereNotNull('mlm_client_agent_id')
        )->orderBy('name')->limit(self::MAX_LISTED_USERS + 1)->get();

        $agents = $applySearch(
            User::query()->where('mlm_role', 'agente')
        )->orderBy('name')->limit(self::MAX_LISTED_USERS + 1)->get();

        return view('admin.mlm.simulator', [
            'pageTitle' => 'MLM — Simulatore compensi',
            'search' => $search,
            'clients' => $clients->take(self::MAX_LISTED_USERS),
            'agents' => $agents->take(self::MAX_LISTED_USERS),
            'clientsTruncated' => $clients->count() > self::MAX_LISTED_USERS,
            'agentsTruncated' => $agents->count() > self::MAX_LISTED_USERS,
            'depositResult' => $depositResult,
            'basiqResult' => $basiqResult,
            'depositError' => $depositError,
            'basiqError' => $basiqError,
            'activeNav' => 'mlm',
        ]);
    }
}
