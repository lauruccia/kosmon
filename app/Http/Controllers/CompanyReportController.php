<?php

namespace App\Http\Controllers;

use App\Services\CompanyReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pagina cliente "Segnala un'azienda" (feature richiesta da Laura il
 * 29/07/2026, vedi CompanyReportService): form di segnalazione in testo
 * libero + storico delle proprie segnalazioni. Aperta a qualsiasi cliente
 * autenticato del portale, indipendentemente dal fatto che sia o meno un
 * cliente MLM con agente assegnato (vedi CompanyReportService::
 * resolveAgentFor(), che ricade sulla radice di sistema se necessario).
 */
class CompanyReportController extends Controller
{
    /**
     * Solo clienti privati (account_holder_type === 'private'): stessa
     * regola di eleggibilita' gia' usata da ReferralBonusService per i
     * bonus segnalazione (aziende/agenti esclusi come beneficiari, per
     * evitare che si auto-segnalino aziende per farmare il bonus).
     */
    private function clientOrAbort(Request $request): \App\Models\User
    {
        $user = $request->user();
        abort_unless($user && $user->account_holder_type === 'private', 403, 'Sezione riservata ai clienti privati.');
        return $user;
    }

    /** GET /segnala-azienda — form + storico delle proprie segnalazioni. */
    public function index(Request $request): View
    {
        $user = $this->clientOrAbort($request);

        $reports = $user->companyReports()
            ->latest()
            ->paginate(15);

        return view('portal.company-reports', [
            'pageTitle' => 'Segnala un\'azienda',
            'reports'   => $reports,
            'activeNav' => 'company-reports',
        ]);
    }

    /** POST /segnala-azienda — invia una nuova segnalazione. */
    public function store(Request $request, CompanyReportService $service): RedirectResponse
    {
        $user = $this->clientOrAbort($request);

        $validated = $request->validate([
            'company_name'  => ['required', 'string', 'max:190'],
            'company_city'  => ['nullable', 'string', 'max:120'],
            'company_notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'company_name.required' => 'Inserisci il nome dell\'azienda che vuoi segnalare.',
        ]);

        $service->submit($user, $validated);

        return back()->with('success', 'Segnalazione inviata! Il tuo agente di riferimento la valuterà a breve.');
    }
}
