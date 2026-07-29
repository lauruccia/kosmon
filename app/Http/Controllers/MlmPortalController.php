<?php

namespace App\Http\Controllers;

use App\Mail\MlmInvitationMail;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\KyCardPurchase;
use App\Models\MlmAgentClosure;
use App\Models\MlmInvitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\MlmAgentCreatedByReferrerNotification;
use App\Services\MlmPayoutService;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

        // Requisiti del grado ATTUALE non piu' soddisfatti (2026-07-22):
        // l'agente vede cosa gli manca per MANTENERE la qualifica prima che
        // il ricalcolo lo retroceda.
        $retention = $rankEngine->currentRankRetention($agent);

        // Riepilogo "Colonne / rami" anche lato agente (2026-07-22, richiesta
        // di Laura): stessa fonte dell'admin (MlmTreeService::branchSummaries)
        // con l'avanzamento verso le colonne da 300 punti calcolato in vista.
        $branches = $tree->branchSummaries($agent);

        // Link "diventa cliente di te stesso" (2026-07-27, richiesta di
        // Laura, punto 1): l'agente non puo' essere contemporaneamente
        // cliente del proprio account, ma puo' registrare un secondo
        // account (email diversa, stesso telefono ammesso) come proprio
        // cliente. Il meccanismo esiste gia' in AuthController::register()
        // + MlmTreeService::resolveAgentForNewClient() (se il referrer e'
        // un agente, il nuovo utente diventa client di QUELL'agente): qui
        // generiamo solo il link referral dell'agente, esattamente come
        // ReferralController::index(), cosi' l'agente puo' condividerlo con
        // se stesso per creare il proprio conto cliente.
        $selfClientRegisterUrl = $agent->referralUrl();

        return view('portal.mlm.struttura', [
            'pageTitle'          => 'La mia struttura',
            'tree'               => $tree->subtree($agent),
            'branches'           => $branches,
            'agent'              => $agent,
            'agentCode'          => $agent->agentCode(),
            'selfClientRegisterUrl' => $selfClientRegisterUrl,
            'activePoints'       => $activePoints,
            'expiringPoints'     => $expiringPoints,
            'rankAtRisk'         => $rankAtRisk,
            'grantedPoints'      => $grantedPoints,
            'grantedLevel1Basic' => $grantedLevel1Basic,
            'nextRank'           => $nextRank,
            'retention'          => $retention,
            'activeNav'          => 'mlm-struttura',
        ]);
    }

    /**
     * GET /portale/mlm/registra-agente — form dedicato con cui l'agente
     * referente registra un nuovo agente sotto di sé (2026-07-28, "gli
     * agenti sotto li registra l'agente referente"). Sostituisce, per chi
     * arriva tramite un agente, il vecchio percorso "spunta la casella in
     * registrazione": quella casella è stata rimossa dalla registrazione
     * pubblica generale (vedi auth/register.blade.php) proprio perché
     * l'unico modo per diventare agente oggi è essere registrati da un
     * agente esistente (questo form) oppure — per chi è già cliente —
     * dal percorso classico richiesta/approvazione admin, invariato.
     */
    public function registraAgente(Request $request): View
    {
        $agent = $this->agentOrAbort($request);

        return view('portal.mlm.registra-agente', [
            'pageTitle'      => 'Registra un nuovo agente',
            'activeNav'      => 'mlm-registra-agente',
            'agent'          => $agent,
            'sponsorOptions' => $this->sponsorOptionsFor($agent),
        ]);
    }

    /**
     * Agenti sotto cui l'agente $agent puo' registrare un nuovo agente:
     * se stesso (default, primo livello sotto di lui) oppure qualunque
     * agente della propria struttura, a qualsiasi profondita' (2026-07-29,
     * richiesta di Laura: "chi lo registra potrebbe decidere di metterlo
     * sotto un agente che lui stesso ha sotto"). Si appoggia alla stessa
     * closure table dell'albero MLM: ogni riga con ancestor_id = $agent->id
     * e' un discendente (o l'agente stesso, depth 0).
     */
    private function sponsorOptionsFor(User $agent): Collection
    {
        $ids = MlmAgentClosure::where('ancestor_id', $agent->id)->pluck('descendant_id');

        return User::whereIn('id', $ids)
            ->where('mlm_role', 'agente')
            ->orderByRaw('id = ? desc', [$agent->id])
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /** POST /portale/mlm/registra-agente */
    public function registraAgenteStore(Request $request): RedirectResponse
    {
        $agent = $this->agentOrAbort($request);

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email', 'unique:users,email'],
            'phone'     => ['nullable', 'string', 'max:30'],
            'sponsor_id' => ['nullable', 'integer'],
        ], [], ['name' => 'nome e cognome', 'email' => 'email', 'phone' => 'telefono', 'sponsor_id' => 'sponsor']);

        // Lo sponsor sotto cui viene registrato il nuovo agente: di default
        // l'agente referente stesso, ma puo' essere qualunque agente della
        // sua struttura (mai un agente esterno — verificato contro la
        // closure table, non ci si puo' semplicemente fidare dell'input).
        $sponsor = $agent;
        if (! empty($validated['sponsor_id']) && (int) $validated['sponsor_id'] !== $agent->id) {
            $allowedIds = $this->sponsorOptionsFor($agent)->pluck('id');
            if (! $allowedIds->contains((int) $validated['sponsor_id'])) {
                return back()->withErrors(['sponsor_id' => 'Sponsor non valido: deve essere un agente della tua struttura.'])->withInput();
            }
            $sponsor = User::findOrFail((int) $validated['sponsor_id']);
        }

        $email = mb_strtolower(trim($validated['email']));
        $temporaryPassword = Str::password(14);

        $newAgent = DB::transaction(function () use ($validated, $email, $agent, $sponsor, $temporaryPassword) {
            $defaultRole = Role::query()->where('slug', 'private-member')->firstOrFail();

            // Creato come 'cliente' con richiesta MLM già "approvata": stesso
            // stato in cui si trova chi ha fatto la richiesta classica ed è
            // stato approvato dall'admin (vedi Admin\MlmAgentRequestController
            // ::approve()/promote()) — nessuna coda di revisione admin per
            // questo percorso, perché è l'agente referente stesso a
            // "vouch-are" per il nuovo agente. mlm_role diventa 'agente' solo
            // alla firma del contratto (MlmAgentContractController::sign()),
            // invariato per TUTTI i percorsi.
            // referred_by_user_id determina lo SPONSOR nell'albero MLM (letto
            // da MlmAgentContractController::sign() via resolveAgentForNewClient()
            // al momento della firma) — puo' differire dall'agente che ha
            // materialmente compilato questo form (vedi $agent vs $sponsor).
            $user = User::create([
                'account_holder_type'        => 'private',
                'name'                       => $validated['name'],
                'email'                      => $email,
                'email_verified_at'          => now(),
                'phone'                      => $validated['phone'] ?? null,
                'password'                   => $temporaryPassword,
                'role'                       => 'registered-private',
                'is_active'                  => true,
                'is_super_admin'             => false,
                'referred_by_user_id'        => $sponsor->id,
                'mlm_role'                   => 'cliente',
                'mlm_client_agent_id'        => null,
                'mlm_agent_request_status'   => 'approved',
                'mlm_agent_requested_at'     => now(),
                'mlm_agent_reviewed_at'      => now(),
                'mlm_agent_reviewed_by'      => $agent->id,
            ]);

            Account::create([
                'owner_user_id'          => $user->id,
                'owner_type'             => 'private',
                'type'                   => 'primary',
                'account_name'           => 'Conto personale ' . $user->name,
                'currency_code'          => 'KY',
                'status'                 => 'active',
                'allow_negative_balance' => false,
                'available_balance'      => 0,
                'pending_balance'        => 0,
            ]);

            $defaultRoleModel = $defaultRole;
            $user->roles()->sync([$defaultRoleModel->id]);

            return $user;
        });

        AuditLog::create([
            'actor_user_id'  => $agent->id,
            'event'          => 'mlm.agent_created_by_referrer',
            'auditable_type' => User::class,
            'auditable_id'   => $newAgent->id,
            'context'        => ['referrer_agent_id' => $agent->id],
        ]);

        $newAgent->notify(new MlmAgentCreatedByReferrerNotification($temporaryPassword, $agent));

        return redirect()->route('portal.mlm.struttura')->with(
            'success',
            "Nuovo agente registrato: {$newAgent->name}. Ha ricevuto un'email con le credenziali di primo accesso; diventerà agente attivo dopo aver firmato il contratto di nomina."
        );
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
