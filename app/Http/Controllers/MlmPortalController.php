<?php

namespace App\Http\Controllers;

use App\Mail\MlmInvitationMail;
use App\Models\KyCardPurchase;
use App\Models\MlmInvitation;
use App\Models\User;
use App\Services\MlmPayoutService;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Pagine MLM del portale agente: Struttura (albero), Clienti, Invitati,
 * Prelievi. Tutte riservate agli utenti con mlm_role = 'agente'.
 */
class MlmPortalController extends Controller
{
    private function agentOrAbort(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->isMlmAgent(), 403, 'Sezione riservata agli agenti KNM.');

        return $user;
    }

    /** GET /portale/mlm/struttura — albero della propria struttura. */
    public function struttura(Request $request, MlmTreeService $tree, MlmRankEngine $rankEngine): View
    {
        $agent = $this->agentOrAbort($request);

        // Avviso punti in scadenza (2026-07-13): somma dei punti che scadranno
        // nei prossimi 30 giorni e verifica se, una volta scaduti, i punti
        // residui scenderebbero sotto il requisito della qualifica attuale
        // (=> retrocessione in arrivo se non si generano nuovi punti).
        $activePoints = $agent->mlmActivePoints();
        $expiringPoints = mlm_points_normalize((float) $agent->mlmPointLedgerEntries()
            ->whereDate('valid_from', '<=', now()->toDateString())
            ->whereDate('valid_until', '>=', now()->toDateString())
            ->whereDate('valid_until', '<=', now()->addDays(30)->toDateString())
            ->sum('points'));

        $pointsRequirement = ['start' => 0, 'basic' => 12, 'key' => 24][$agent->mlm_rank] ?? 48;
        $rankAtRisk = $agent->mlm_rank !== 'start'
            && $expiringPoints > 0
            && ($activePoints - $expiringPoints) < $pointsRequirement;

        // Punti/agenti "omaggio" assegnati da un admin (2026-07-14): mostrati
        // distintamente dai punti maturati da clienti reali, su richiesta di
        // Laura ("visibile anche all'agente").
        $grantedPoints = $agent->mlmGrantedPoints();
        $grantedLevel1Basic = $agent->mlmGrantedLevel1Basic();

        // Checklist "cosa mi manca per la prossima qualifica" (2026-07-21,
        // richiesta di Laura): stessa fonte dell'admin (MlmRankEngine::
        // nextRankRequirements), null quando l'agente e' gia' Manager.
        $nextRank = $rankEngine->nextRankRequirements($agent);

        return view('portal.mlm.struttura', [
            'pageTitle'          => 'La mia struttura',
            'tree'               => $tree->subtree($agent),
            'agent'              => $agent,
            'activePoints'       => $activePoints,
            'expiringPoints'     => $expiringPoints,
            'rankAtRisk'         => $rankAtRisk,
            'grantedPoints'      => $grantedPoints,
            'grantedLevel1Basic' => $grantedLevel1Basic,
            'nextRank'           => $nextRank,
            'activeNav'          => 'mlm-struttura',
        ]);
    }

    /** GET /portale/mlm/clienti — clienti collegati con acquisti KYCard. */
    public function clienti(Request $request): View
    {
        $agent = $this->agentOrAbort($request);

        $clients = $agent->mlmClients()
            ->select('id', 'name', 'email', 'created_at')
            ->orderByDesc('created_at')
            ->paginate(25);

        $stats = KyCardPurchase::whereIn('user_id', $clients->pluck('id'))
            ->where('status', 'completed')
            ->selectRaw('user_id, count(*) as purchases, sum(price_eur_cents) as total_eur_cents, max(id) as last_purchase_id')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $lastAmounts = KyCardPurchase::whereIn('id', $stats->pluck('last_purchase_id'))
            ->pluck('price_eur_cents', 'id');

        return view('portal.mlm.clienti', [
            'pageTitle'   => 'I miei clienti',
            'clients'     => $clients,
            'stats'       => $stats,
            'lastAmounts' => $lastAmounts,
            'activeNav'   => 'mlm-clienti',
        ]);
    }

    /** GET /portale/mlm/invitati — inviti email + registrati con il link. */
    public function invitati(Request $request): View
    {
        $agent = $this->agentOrAbort($request);

        $invitations = MlmInvitation::where('agent_user_id', $agent->id)
            ->with('registeredUser:id,name,email,mlm_role')
            ->latest()
            ->paginate(25, ['*'], 'inviti_page');

        $referrals = $agent->referrals()
            ->select('id', 'name', 'email', 'mlm_role', 'created_at')
            ->latest()
            ->paginate(25, ['*'], 'registrati_page');

        return view('portal.mlm.invitati', [
            'pageTitle'   => 'I miei inviti',
            'invitations' => $invitations,
            'referrals'   => $referrals,
            'referralUrl' => $agent->referralUrl(),
            'activeNav'   => 'mlm-invitati',
        ]);
    }

    /** POST /portale/mlm/invitati — invia un nuovo invito email. */
    public function invitatiStore(Request $request): RedirectResponse
    {
        $agent = $this->agentOrAbort($request);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name'  => ['nullable', 'string', 'max:120'],
        ], [], ['email' => 'email', 'name' => 'nome']);

        $email = mb_strtolower(trim($validated['email']));

        if (User::where('email', $email)->exists()) {
            return back()->withErrors(['email' => 'Questa email risulta gia\' registrata su KMoney.'])->withInput();
        }

        if (MlmInvitation::where('agent_user_id', $agent->id)->where('email', $email)->exists()) {
            return back()->withErrors(['email' => 'Hai gia\' invitato questa email: puoi reinviare l\'invito dalla tabella qui sotto.'])->withInput();
        }

        $invitation = MlmInvitation::create([
            'agent_user_id' => $agent->id,
            'email'         => $email,
            'name'          => $validated['name'] ?? null,
            'status'        => 'pending',
            'sent_at'       => now(),
        ]);

        Mail::send(new MlmInvitationMail($invitation));

        return back()->with('status', 'Invito inviato a ' . $email . '.');
    }

    /** POST /portale/mlm/invitati/{invitation}/reinvia */
    public function invitatiResend(Request $request, MlmInvitation $invitation): RedirectResponse
    {
        $agent = $this->agentOrAbort($request);

        abort_unless($invitation->agent_user_id === $agent->id, 403);

        if (! $invitation->isPending()) {
            return back()->withErrors(['email' => 'Questo invito risulta gia\' registrato.']);
        }

        if ($invitation->sent_at && $invitation->sent_at->gt(now()->subMinutes(15))) {
            return back()->withErrors(['email' => 'Hai reinviato questo invito da poco: attendi qualche minuto.']);
        }

        $invitation->forceFill(['sent_at' => now()])->save();

        Mail::send(new MlmInvitationMail($invitation));

        return back()->with('status', 'Invito reinviato a ' . $invitation->email . '.');
    }

    /** DELETE /portale/mlm/invitati/{invitation} */
    public function invitatiDestroy(Request $request, MlmInvitation $invitation): RedirectResponse
    {
        $agent = $this->agentOrAbort($request);

        abort_unless($invitation->agent_user_id === $agent->id, 403);

        if (! $invitation->isPending()) {
            return back()->withErrors(['email' => 'Non puoi eliminare un invito gia\' registrato.']);
        }

        $invitation->delete();

        return back()->with('status', 'Invito eliminato.');
    }

    /** GET /portale/mlm/prelievi — storico prelievi + maturato disponibile. */
    public function prelievi(Request $request, MlmPayoutService $payouts): View
    {
        $agent = $this->agentOrAbort($request);

        return view('portal.mlm.prelievi', [
            'pageTitle'      => 'Storico prelievi',
            'payouts'        => $agent->mlmPayouts()->latest()->paginate(20),
            'availableCents' => $payouts->pendingWithdrawableCents($agent),
            'hasOpenPayout'  => $payouts->hasOpenPayout($agent),
            'paymentDetail'  => $agent->mlmPaymentDetail,
            'activeNav'      => 'mlm-prelievi',
        ]);
    }

    /** POST /portale/mlm/prelievi — richiede il prelievo di tutto il maturato. */
    public function prelieviStore(Request $request, MlmPayoutService $payouts): RedirectResponse
    {
        $agent = $this->agentOrAbort($request);

        if (! $agent->mlmPaymentDetail) {
            return redirect()->route('portal.mlm.payment-details.edit')
                ->withErrors(['iban' => 'Prima di richiedere un prelievo devi salvare i tuoi dati bancari (IBAN).']);
        }

        try {
            $payout = $payouts->requestWithdrawal($agent);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['prelievo' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            'Richiesta di prelievo di € %s inviata: riceverai il bonifico dopo l\'approvazione dell\'amministrazione.',
            number_format($payout->total_eur_cents / 100, 2, ',', '.')
        ));
    }
}
