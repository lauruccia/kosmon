<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MlmAgentContractSignature;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\MlmAgentActivatedNotification;
use App\Notifications\MlmAgentContractOtpNotification;
use App\Services\MlmTreeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Firma del contratto di nomina ad Agente KNM, ultimo passo del percorso
 * richiesta -> approvazione admin -> firma contratto -> mlm_role = 'agente'.
 * Stesso schema OTP via email del ContractController principale.
 */
class MlmAgentContractController extends Controller
{
    /** GET /portale/mlm/contratto-agente */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->isMlmAgent()) {
            return redirect()->route('portal.dashboard')->with('info', 'Sei già un agente KNM.');
        }

        if (! $user->mlmAgentAwaitingContract()) {
            return redirect()->route('portal.mlm.agent-request.show');
        }

        $settings       = SystemSetting::agentContractSettings();
        $contractHtml   = $settings->renderAgentContractText($user);
        $contractVer    = $settings->mlm_agent_contract_version ?? 1;
        // 2026-08-07: le Direttive e Procedure Kosmos sono un secondo
        // documento (nessun placeholder personale) da leggere e accettare
        // insieme al contratto, con la stessa firma OTP — vedi sign().
        $directivesHtml = $settings->mlm_agent_directives_text ?? SystemSetting::defaultAgentDirectivesText();
        $directivesVer  = $settings->mlm_agent_directives_version ?? 1;

        return view('portal.mlm.agent-contract-sign', [
            'pageTitle'      => 'Contratto di nomina ad agente KNM',
            'user'           => $user,
            'contractHtml'   => $contractHtml,
            'contractVer'    => $contractVer,
            'directivesHtml' => $directivesHtml,
            'directivesVer'  => $directivesVer,
            // 2026-08-14: campi anagrafici del "modulo di adesione" ancora da
            // compilare — finche' ce n'e' almeno uno la view mostra il form e
            // nasconde la firma OTP (vedi missingAgentContractFields()).
            'missingFields'  => $user->missingAgentContractFields(),
            'activeNav'      => 'mlm-agent-request',
        ]);
    }

    /**
     * POST /portale/mlm/contratto-agente/dati — 2026-08-14 (richiesta di
     * Laura): l'aspirante agente compila qui i dati che il contratto stampa
     * ma che nessuno gli ha mai chiesto (chi arriva dalla richiesta classica
     * o da una promozione admin non li ha: li raccoglie solo il form
     * "Registra agente"). Senza questo passo il modulo di adesione ex art. 19
     * D. Lgs. 114/98 veniva firmato — e congelato nello snapshot — con le
     * caselle anagrafiche vuote.
     *
     * Stesse regole di MlmPortalController::registraAgenteStore(), cosi' i
     * due percorsi producono contratti equivalenti; l'unica differenza e' che
     * l'unicita' del codice fiscale ignora l'utente stesso (potrebbe gia'
     * averlo inserito in registrazione).
     */
    public function updateData(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->mlmAgentAwaitingContract(), 403);

        $minBirthDate = now()->subYears(18)->toDateString();

        $validated = $request->validate([
            'phone'              => ['nullable', 'string', 'max:30'],
            'fiscal_code'        => ['required', 'string', 'size:16', 'regex:/^[A-Za-z0-9]{16}$/', Rule::unique('users', 'fiscal_code')->ignore($user->id)],
            'birth_date'         => ['required', 'date', 'before_or_equal:' . $minBirthDate],
            'birth_place'        => ['required', 'string', 'max:100'],
            'residence_address'  => ['required', 'string', 'max:190'],
            'residence_zip'      => ['required', 'string', 'max:10'],
            'residence_city'     => ['required', 'string', 'max:100'],
            'residence_province' => ['required', 'string', 'size:2', 'alpha'],
        ], [
            'fiscal_code.size'           => 'Il codice fiscale deve essere di 16 caratteri alfanumerici.',
            'fiscal_code.regex'          => 'Il codice fiscale deve essere di 16 caratteri alfanumerici.',
            'fiscal_code.unique'         => 'Questo codice fiscale risulta già registrato su KMoney.',
            'birth_date.before_or_equal' => 'Per diventare incaricato di vendita devi avere almeno 18 anni (art. 6 delle Condizioni Generali).',
            'residence_province.size'    => 'Indica la provincia con la sigla di 2 lettere (es. RM).',
            'residence_province.alpha'   => 'Indica la provincia con la sigla di 2 lettere (es. RM).',
        ], [
            'phone' => 'telefono', 'fiscal_code' => 'codice fiscale', 'birth_date' => 'data di nascita',
            'birth_place' => 'luogo di nascita', 'residence_address' => 'indirizzo di residenza',
            'residence_zip' => 'CAP', 'residence_city' => 'comune di residenza',
            'residence_province' => 'provincia di residenza',
        ]);

        $validated['fiscal_code']        = mb_strtoupper(trim($validated['fiscal_code']));
        $validated['residence_province'] = mb_strtoupper(trim($validated['residence_province']));

        // Un OTP eventualmente gia' richiesto si riferisce al testo del
        // contratto PRIMA di questa modifica: lo invalidiamo, cosi' l'utente
        // rilegge il modulo compilato e richiede un codice nuovo. Evita che
        // si firmi uno snapshot diverso da quello effettivamente letto.
        $user->forceFill($validated + [
            'mlm_agent_contract_otp'            => null,
            'mlm_agent_contract_otp_expires_at' => null,
        ])->save();

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'mlm.agent_contract.data_completed',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'context'        => ['fields' => array_keys($validated)],
        ]);

        return redirect()->route('portal.mlm.agent-contract.show')
            ->with('data_saved', true);
    }

    /**
     * GET /portale/mlm/contratto-agente/firmato — sola lettura, per
     * rivedere il contratto agente + le Direttive già firmate (2026-08-07,
     * richiesta di Laura: show() sopra reindirizza via una volta che
     * l'utente è già agente, quindi senza questa pagina non c'era alcun
     * modo di riconsultarli). Mostra lo SNAPSHOT congelato al momento della
     * firma, non il testo di default/attuale — coerente con
     * ContractController::viewSigned() per il contratto generale.
     */
    public function viewSigned(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $signature = MlmAgentContractSignature::where('user_id', $user->id)
            ->latest('signed_at')
            ->first();

        if (! $signature) {
            return redirect()->route('portal.dashboard')
                ->with('info', 'Non risulta ancora nessun contratto agente firmato.');
        }

        return view('portal.mlm.agent-contract-view', [
            'pageTitle'      => 'Il mio contratto agente KNM',
            'user'           => $user,
            'signature'      => $signature,
            'contractHtml'   => $signature->contract_html_snapshot,
            // Le firme precedenti al 2026-08-07 non hanno le Direttive
            // (documento introdotto in quella data): in quel caso non
            // mostriamo nulla di "accettato" che in realtà non lo era.
            'directivesHtml' => $signature->directives_html_snapshot,
            'activeNav'      => 'mlm-agent-contract',
        ]);
    }

    /** POST /portale/mlm/contratto-agente/otp */
    public function sendOtp(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->mlmAgentAwaitingContract(), 403);

        // Niente OTP finche' il modulo non e' compilato: la view nasconde gia'
        // il pulsante, questa e' la guardia lato server (2026-08-14).
        if (! $user->hasCompleteAgentContractData()) {
            return redirect()->route('portal.mlm.agent-contract.show')
                ->withErrors(['general' => 'Completa prima i tuoi dati anagrafici: sono parte integrante del contratto da firmare.']);
        }

        $otp     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(15);

        $user->forceFill([
            'mlm_agent_contract_otp'            => $otp,
            'mlm_agent_contract_otp_expires_at' => $expires,
        ])->save();

        $user->notify(new MlmAgentContractOtpNotification($otp));

        return redirect()->route('portal.mlm.agent-contract.show')
            ->with('otp_sent', true)
            ->with('otp_email', $user->email);
    }

    /** POST /portale/mlm/contratto-agente/firma */
    public function sign(Request $request, MlmTreeService $mlmTree): RedirectResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ], [
            'otp.required' => 'Inserisci il codice OTP ricevuto via email.',
            'otp.size'     => 'Il codice deve essere di 6 cifre.',
            'otp.regex'    => 'Il codice deve contenere solo cifre.',
        ]);

        $user = $request->user();

        abort_unless($user->mlmAgentAwaitingContract(), 403);

        // Ultima barriera prima di congelare lo snapshot: mai una firma su un
        // modulo di adesione con le caselle anagrafiche vuote (2026-08-14).
        if (! $user->hasCompleteAgentContractData()) {
            return redirect()->route('portal.mlm.agent-contract.show')
                ->withErrors(['general' => 'Completa prima i tuoi dati anagrafici: sono parte integrante del contratto da firmare.']);
        }

        if (
            ! $user->mlm_agent_contract_otp
            || ! $user->mlm_agent_contract_otp_expires_at
            || now()->isAfter($user->mlm_agent_contract_otp_expires_at)
        ) {
            return back()->withErrors(['otp' => 'Il codice OTP è scaduto. Richiedi un nuovo codice.']);
        }

        if (! hash_equals($user->mlm_agent_contract_otp, $request->input('otp'))) {
            return back()->withErrors(['otp' => 'Codice OTP non corretto.'])->withInput();
        }

        $settings          = SystemSetting::agentContractSettings();
        $contractHtml      = $settings->renderAgentContractText($user);
        $contractVersion   = $settings->mlm_agent_contract_version ?? 1;
        // Stesso atto di firma copre anche le Direttive e Procedure Kosmos
        // (2026-08-07): congeliamo anche questo snapshot, così come per il
        // contratto, per avere prova di cosa esattamente è stato accettato.
        $directivesHtml    = $settings->mlm_agent_directives_text ?? SystemSetting::defaultAgentDirectivesText();
        $directivesVersion = $settings->mlm_agent_directives_version ?? 1;
        $now               = now();

        MlmAgentContractSignature::create([
            'user_id'                  => $user->id,
            'contract_version'         => $contractVersion,
            'contract_html_snapshot'   => $contractHtml,
            'directives_version'       => $directivesVersion,
            'directives_html_snapshot' => $directivesHtml,
            // 2026-07-31: congela i dati anagrafici del firmatario e dello
            // sponsor al momento della firma, in colonna strutturata (query-
            // abile) oltre allo snapshot HTML — vedi SystemSetting::
            // renderAgentContractText() per i placeholder equivalenti.
            'signer_data_snapshot'   => [
                'name'                => $user->name,
                'email'               => $user->email,
                'phone'               => $user->phone,
                'fiscal_code'         => $user->fiscal_code,
                'birth_date'          => $user->birth_date?->toDateString(),
                'birth_place'         => $user->birth_place,
                'residence_address'   => $user->residence_address,
                'residence_zip'       => $user->residence_zip,
                'residence_city'      => $user->residence_city,
                'residence_province'  => $user->residence_province,
                'sponsor_name'        => $user->referredBy?->name,
                'sponsor_agent_code'  => $user->referredBy?->mlm_agent_code,
            ],
            'signed_at'              => $now,
            'ip_address'             => $request->ip(),
            'user_agent'             => $request->userAgent(),
        ]);

        // Sponsor nell'albero MLM: il primo agente antenato nella catena di chi
        // ha invitato questo utente (stessa regola usata in registrazione).
        $sponsor = $mlmTree->resolveAgentForNewClient($user->referredBy);

        $user->forceFill([
            'mlm_agent_contract_signed_at'       => $now,
            'mlm_agent_contract_otp'             => null,
            'mlm_agent_contract_otp_expires_at'  => null,
            'mlm_role'                           => 'agente',
            'mlm_activated_at'                   => $now,
            'mlm_client_agent_id'                => null,
        ])->save();

        $mlmTree->attachAgent($user, $sponsor);

        // Codice agente (punto 5, 28/07/2026): assegnato qui, nel momento
        // esatto in cui mlm_role diventa 'agente' — vale sia per chi arriva
        // dalla richiesta/approvazione classica sia per chi è stato
        // registrato direttamente da un altro agente (vedi
        // MlmPortalController::registraAgenteStore()). Immutabile una volta
        // assegnato, vedi User::agentCode().
        $user->agentCode();

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'mlm.agent_contract.signed',
            'auditable_type' => \App\Models\User::class,
            'auditable_id'   => $user->id,
            'context'        => ['contract_version' => $contractVersion, 'sponsor_user_id' => $sponsor?->id],
        ]);

        $user->notify(new MlmAgentActivatedNotification());

        // Bonus segnalazione "agente" (punto 3, 27/07): erogato al
        // segnalante di $user solo ora che è ufficialmente agente (contratto
        // firmato). Non cumulativo: se $user aveva già fatto scattare il
        // bonus "amico" alla registrazione (si era registrato come privato),
        // il segnalante riceve solo la differenza fino a questo livello —
        // vedi ReferralBonusService.
        app(\App\Services\ReferralBonusService::class)->awardTier(
            $user,
            \App\Services\ReferralBonusService::TIER_AGENTE,
        );

        return redirect()->route('portal.mlm.struttura')
            ->with('success', 'Contratto firmato: sei ufficialmente un agente KNM! Benvenuto nel programma.');
    }
}
