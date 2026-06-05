<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\WebAuthnController;
use App\Http\Controllers\SubAccountInvitationController;
use App\Http\Controllers\SubAccountLimitRequestController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminSectorController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\CashbackRuleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrokerController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\IncassoQrController;
use App\Http\Controllers\NfcPaymentController;
use App\Http\Controllers\SonicPaymentController;
use App\Http\Controllers\PaymentHandlerController;
use App\Http\Controllers\CodePaymentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\ScheduledPaymentController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\TextPaymentRequestController;
use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PaymentRequestController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PaymentPlanController;
use App\Http\Controllers\NettingController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\StepUpController;
use App\Http\Controllers\KyCardController;
use App\Http\Controllers\AdminKyCardController;
use App\Http\Controllers\BalanceAlertController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginLogController;
use App\Http\Controllers\ReceiptController;
use App\Models\Transfer;
use App\Services\TransferBookingService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Http\Controllers\NotificationPreferencesController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\AdminFeeController;
use App\Http\Controllers\AdminBroadcastController;
use App\Http\Controllers\Admin\AdminNfcCardController;
use App\Http\Controllers\NfcCardController;
use App\Http\Controllers\NfcCardPaymentController;

Route::get('/', [HomeController::class, 'index'])->name('home');

// ── TEST PUSH TEMPORANEO (rimuovere dopo il test) ───────────────────────────
Route::get('/dev-push-test', function () {
    abort_unless(app()->environment('local', 'production'), 403);
    $email = request('email', 'sitireggiocal@gmail.com');
    $user  = \App\Models\User::where('email', $email)->firstOrFail();
    $subscriptions = \App\Models\PushSubscription::where('user_id', $user->id)->get();

    $vapidAvailable = config('webpush.vapid.public_key') && config('webpush.vapid.private_key');
    if (!$vapidAvailable) {
        return response()->json(['error' => 'VAPID keys non configurate']);
    }

    $results = [];
    try {
        $webPush = new \Minishlink\WebPush\WebPush(['VAPID' => [
            'subject'    => config('webpush.vapid.subject', 'mailto:noreply@kmoney.it'),
            'publicKey'  => config('webpush.vapid.public_key'),
            'privateKey' => config('webpush.vapid.private_key'),
        ]]);
        $payload = json_encode(['title' => '🔔 KMoney Test', 'body' => 'Push funziona!', 'url' => '/dashboard', 'tag' => 'test-push']);
        foreach ($subscriptions as $sub) {
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint'        => $sub->endpoint,
                'publicKey'       => $sub->public_key,
                'authToken'       => $sub->auth_token,
                'contentEncoding' => $sub->content_encoding ?? 'aesgcm',
            ]);

            $webPush->queueNotification($subscription, $payload);
        }
        foreach ($webPush->flush() as $report) {
            $results[] = [
                'endpoint' => substr($report->getEndpoint(), 0, 60) . '...',
                'success'  => $report->isSuccess(),
                'expired'  => $report->isSubscriptionExpired(),
                'reason'   => $report->isSuccess() ? 'ok' : $report->getReason(),
            ];
            if ($report->isSubscriptionExpired()) {
                \App\Models\PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    } catch (\Throwable $e) {
        return response()->json(['error' => $e->getMessage()]);
    }

    return response()->json(['subscriptions' => count($subscriptions), 'results' => $results]);
});

// Pagina diagnostica push per telefono
Route::get('/dev-push-phone', function () {
    abort_unless(app()->environment('local', 'production'), 403);
    $csrfToken = csrf_token();
    $vapidKey  = config('webpush.vapid.public_key');
    return response("<!DOCTYPE html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>KMoney Push Test</title></head><body style='font-family:sans-serif;padding:20px;max-width:500px'>
<h2>🔔 Test Push Telefono</h2><div id='log' style='background:#f0f0f0;padding:10px;border-radius:8px;font-size:13px;white-space:pre-wrap'></div>
<button onclick='runTest()' style='margin-top:16px;padding:12px 24px;background:#1a4fc9;color:#fff;border:none;border-radius:8px;font-size:16px;cursor:pointer'>Avvia Test</button>
<script>
var VAPID='$vapidKey', CSRF='$csrfToken';
function log(msg){document.getElementById('log').textContent+=msg+'\\n';}
function urlB64(b){var p='='.repeat((4-b.length%4)%4),s=(b+p).replace(/-/g,'+').replace(/_/g,'/'),r=atob(s),o=new Uint8Array(r.length);for(var i=0;i<r.length;i++)o[i]=r.charCodeAt(i);return o;}
async function runTest(){
  log('1. Permission: '+Notification.permission);
  log('2. ServiceWorker: '+('serviceWorker' in navigator));
  log('3. PushManager: '+('PushManager' in window));
  if(!('serviceWorker' in navigator)||!('PushManager' in window)){log('❌ Non supportato');return;}
  var perm=await Notification.requestPermission();
  log('4. Permesso richiesto: '+perm);
  if(perm!=='granted'){log('❌ Permesso negato');return;}
  try{
    var reg=await navigator.serviceWorker.ready;
    log('5. SW pronto: '+reg.scope);
    var existing=await reg.pushManager.getSubscription();
    log('6. Subscription esistente: '+(existing?'SÌ':'NO'));
    var sub=existing||await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:urlB64(VAPID)});
    log('7. Subscription endpoint: '+sub.endpoint.substring(0,50)+'...');
    var key=sub.getKey('p256dh'),auth=sub.getKey('auth');
    var r=await fetch('/push/subscribe',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},body:JSON.stringify({endpoint:sub.endpoint,keys:{p256dh:key?btoa(String.fromCharCode.apply(null,new Uint8Array(key))):'',auth:auth?btoa(String.fromCharCode.apply(null,new Uint8Array(auth))):''},contentEncoding:(PushManager.supportedContentEncodings||['aesgcm'])[0]})});
    log('8. POST /push/subscribe: '+r.status+(r.ok?' ✅ SALVATA':' ❌ ERRORE'));
  }catch(e){log('❌ Errore: '+e.message);}
}
</script></body></html>", 200, ['Content-Type' => 'text/html']);
});
// ───────────────────────────────────────────────────────────────────────────

// -- Landing NFC card (apertura URL dal chip) ----------------------------
Route::get('/nfc/{uuid}', [NfcCardPaymentController::class, 'scanLanding'])->name('nfc.card.scan-landing');

// -- Autorizzazione pagamento Card NFC (accessibile anche via URL firmato) --
Route::get('/nfc/card/authorize/{nonce}', [NfcCardPaymentController::class, 'authorizeForm'])->name('nfc.card.authorize');
Route::post('/nfc/card/authorize/{nonce}', [NfcCardPaymentController::class, 'authorize'])->name('nfc.card.authorize.post');


// ── Centro assistenza (pubblico) ─────────────────────────────────────────────
Route::get('/assistenza', [HelpController::class, 'index'])->name('help.index');
Route::post('/assistenza/contatto', [HelpController::class, 'contact'])->name('help.contact')->middleware('throttle:5,3');

// ── Documenti legali (pubblici) ───────────────────────────────────────────────
Route::get('/legale/contratto', [LegalController::class, 'contract'])->name('legal.contract');
Route::get('/legale/aml-kyc',   [LegalController::class, 'amlKyc'])->name('legal.aml-kyc');
Route::get('/legale/limiti',    [LegalController::class, 'limits'])->name('legal.limits');
Route::get('/legale/reclami',   [LegalController::class, 'complaints'])->name('legal.complaints');

// ── OpenAPI spec (pubblico) ───────────────────────────────────────────────────
Route::get('/api/openapi.json', [DocsController::class, 'openApiJson'])->name('api.openapi-json');


// Health check — nessuna auth richiesta
Route::get('/health', function () {
    $checks = [];
    $overallOk = true;

    // Database
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (\Throwable $e) {
        $checks['database'] = 'error';
        $overallOk = false;
    }

    // Cache (usa il driver configurato — redis in prod, database in dev)
    try {
        $cacheKey = '_health_' . uniqid();
        \Illuminate\Support\Facades\Cache::put($cacheKey, 1, 10);
        $hit = \Illuminate\Support\Facades\Cache::get($cacheKey) === 1;
        \Illuminate\Support\Facades\Cache::forget($cacheKey);
        $checks['cache'] = $hit ? 'ok' : 'error';
        if (!$hit) $overallOk = false;
    } catch (\Throwable) {
        $checks['cache'] = 'error';
        $overallOk = false;
    }

    // Redis (solo se configurato come driver)
    $redisDriver = config('database.redis.client');
    $cacheStore  = config('cache.default');
    $queueConn   = config('queue.default');
    $usesRedis   = in_array('redis', [$cacheStore, $queueConn], true);

    if ($usesRedis) {
        try {
            \Illuminate\Support\Facades\Redis::ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable) {
            $checks['redis'] = 'error';
            $overallOk = false;
        }
    } else {
        $checks['redis'] = 'not_configured';
    }

    // Queue worker — verifica che ci sia almeno un job processato di recente
    // (presenza tabella jobs e failed_jobs — indica che il worker e' attivo)
    try {
        $failedRecent = \Illuminate\Support\Facades\DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subMinutes(5))
            ->count();
        $checks['queue_failed_recent'] = $failedRecent > 0
            ? 'warning:' . $failedRecent . '_failed_in_last_5min'
            : 'ok';
    } catch (\Throwable) {
        $checks['queue_failed_recent'] = 'unknown';
    }

    // Versione app e ambiente
    $meta = [
        'app_env'   => app()->environment(),
        'app_debug' => config('app.debug') ? 'true' : 'false',
        'php'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
    ];

    $status = $overallOk ? 'ok' : 'degraded';
    return response()->json([
        'status'    => $status,
        'timestamp' => now()->toIso8601String(),
        'checks'    => $checks,
        'meta'      => $meta,
    ], $overallOk ? 200 : 503);
})->name('health');

// Pagine legali e pubbliche — no auth
Route::get('/privacy', fn() => view('legal.privacy'))->name('legal.privacy');
Route::get('/termini', fn() => view('legal.terms'))->name('legal.terms');
Route::get('/cookie-policy', fn() => view('legal.cookies'))->name('legal.cookies');
Route::get('/contact', fn() => redirect('/#contact'))->name('contact');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt')->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');

    Route::get('/forgot-password', fn () => view('auth.forgot-password'))->name('password.request');

    Route::post('/forgot-password', function (Request $request) {
        $request->validate(['email' => ['required', 'email']]);
        try {
            $status = Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Password reset mail failed: ' . $e->getMessage());
            return back()->withInput()->withErrors(['email' => __('Impossibile inviare la mail. Riprova tra qualche minuto o contatta il supporto.')]);
        }
        return match ($status) {
            Password::RESET_LINK_SENT => back()->with('status', __('Ti abbiamo inviato un link per reimpostare la password.')),
            Password::INVALID_USER    => back()->withInput()->withErrors(['email' => __('Nessun account trovato con questa email.')]),
            Password::RESET_THROTTLED => back()->withInput()->withErrors(['email' => __('Troppe richieste. Attendi qualche minuto prima di riprovare.')]),
            default                   => back()->withInput()->withErrors(['email' => __('Errore imprevisto. Riprova.')]),
        };
    })->name('password.email');

    Route::get('/reset-password/{token}', function (string $token, Request $request) {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    })->name('password.reset');

    Route::post('/reset-password', function (Request $request) {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])
                     ->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );
        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('portal_success', 'Password reimpostata correttamente.')
            : back()->withInput()->withErrors(['email' => __($status)]);
    })->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ── WebAuthn (Passkey / impronta) ─────────────────────────────────────────────
// Endpoint guest: challenge e verifica per il login
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/webauthn/login/options', [WebAuthnController::class, 'loginOptions'])->name('webauthn.login.options');
    Route::post('/webauthn/login/verify',  [WebAuthnController::class, 'loginVerify'])->name('webauthn.login.verify');
});

// Endpoint autenticati: registrazione e gestione dispositivi
Route::middleware(['auth', 'verified', 'twofactor', 'onboarding', 'contract'])->prefix('webauthn')->name('webauthn.')->group(function () {
    Route::post('/register/options',   [WebAuthnController::class, 'registerOptions'])->name('register.options');
    Route::post('/register/verify',    [WebAuthnController::class, 'registerVerify'])->name('register.verify');
    Route::get('/credentials',         [WebAuthnController::class, 'listCredentials'])->name('credentials');
    Route::delete('/credentials/{id}', [WebAuthnController::class, 'deleteCredential'])->name('credentials.delete');
    Route::post('/switch/options',     [WebAuthnController::class, 'switchOptions'])->name('switch.options');
    Route::post('/switch/verify',      [WebAuthnController::class, 'switchVerify'])->name('switch.verify');
});
// ─────────────────────────────────────────────────────────────────────────────

// Email verification
Route::middleware('auth')->group(function () {
    Route::get('/email/verifica', fn () => view('auth.verify-email'))->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (\Illuminate\Foundation\Auth\EmailVerificationRequest $request) {
        $request->fulfill();
        return redirect()->route('portal.dashboard')->with('portal_success', 'Email verificata correttamente.');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', function (\Illuminate\Http\Request $request) {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('portal.dashboard');
        }
        $request->user()->sendEmailVerificationNotification();
        return back()->with('portal_success', 'Link di verifica inviato. Controlla la tua email.');
    })->middleware('throttle:6,1')->name('verification.send');
});

// 2FA challenge (auth only — no twofactor/onboarding middleware to avoid loops)
Route::middleware('auth')->group(function () {
    Route::get('/2fa/verifica', [TwoFactorController::class, 'showChallenge'])->name('2fa.challenge');
    Route::post('/2fa/verifica', [TwoFactorController::class, 'verifyChallenge'])->name('2fa.verify');
});

// ── Onboarding wizard (auth, senza middleware 'onboarding' per evitare loop) ──
Route::middleware('auth')->prefix('benvenuto')->name('onboarding.')->group(function () {
    Route::get('/',           [OnboardingController::class, 'step0'])->name('step0');
    Route::get('/fatto',      [OnboardingController::class, 'completed'])->name('completed');
    Route::get('/profilo',    [OnboardingController::class, 'step1'])->name('step1');
    Route::post('/profilo',   [OnboardingController::class, 'saveStep1'])->name('step1.save');
    Route::get('/documenti',  [OnboardingController::class, 'step2'])->name('step2');
    Route::post('/documenti/upload', [OnboardingController::class, 'uploadKyc'])->name('step2.upload');
    Route::post('/documenti/invia',  [OnboardingController::class, 'proceedToWaiting'])->name('step2.proceed');
    Route::get('/attesa',     [OnboardingController::class, 'step3'])->name('step3');
});

// Stripe webhook — MUST be outside auth middleware
Route::post('/stripe/webhook', [KyCardController::class, 'stripeWebhook'])->name('stripe.webhook');

// ─── Sottoconti — inviti pubblici (nessuna auth richiesta) ──────────────────
Route::get('/invito-sottoconto/{token}', [SubAccountInvitationController::class, 'show'])->name('subaccount.invitation.show');
Route::get('/invito-sottoconto/{token}/registra', [SubAccountInvitationController::class, 'showRegister'])->name('subaccount.invitation.register');
Route::post('/invito-sottoconto/{token}/registra', [SubAccountInvitationController::class, 'register'])->name('subaccount.invitation.register.post');

// Accettazione da utente gia registrato (richiede auth)
Route::middleware(['auth'])->group(function () {
    Route::post('/invito-sottoconto/{accountId}/accetta', [SubAccountInvitationController::class, 'acceptExisting'])->name('subaccount.invitation.accept-existing');
});


// ── Contratto di adesione — firma con OTP (auth, verified, twofactor; NO onboarding/contract per evitare loop) ──
Route::middleware(['auth', 'verified', 'twofactor'])->prefix('contratto')->name('portal.contract.')->group(function () {
    Route::get('/firma',      [ContractController::class, 'show'])->name('sign');
    Route::post('/otp',       [ContractController::class, 'sendOtp'])->name('send-otp')->middleware('throttle:3,10');
    Route::post('/firma',     [ContractController::class, 'sign'])->name('sign.post')->middleware('throttle:10,1');
    Route::post('/rimanda',   [ContractController::class, 'postpone'])->name('postpone');
    Route::get('/mio-contratto', [ContractController::class, 'viewSigned'])->name('view');
    Route::get('/scarica',       [ContractController::class, 'downloadSigned'])->name('download');
});

Route::middleware(['auth', 'verified', 'twofactor', 'onboarding', 'contract'])->group(function () {

    Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('portal.dashboard');

    // Sicurezza account / 2FA setup
    // Step-up authentication
    Route::get('/profilo/conferma-identita', [StepUpController::class, 'show'])->name('portal.step-up.show');
    Route::post('/profilo/conferma-identita', [StepUpController::class, 'verify'])->name('portal.step-up.verify');

    Route::get('/profilo/sicurezza', [TwoFactorController::class, 'showSetup'])->name('portal.security');
    Route::get('/sessioni', [LoginLogController::class, 'index'])->name('portal.login-logs');
    Route::post('/sessioni/logout-all', [LoginLogController::class, 'logoutAll'])->name('portal.login-logs.logout-all');
    Route::delete('/sessioni/{sessionId}', [LoginLogController::class, 'logoutSession'])->name('portal.login-logs.logout-session');
    // Beneficiari
    Route::get('/beneficiari', [BeneficiaryController::class, 'index'])->name('portal.beneficiaries.index');
    Route::post('/beneficiari', [BeneficiaryController::class, 'store'])->name('portal.beneficiaries.store');
    Route::patch('/beneficiari/{beneficiary}', [BeneficiaryController::class, 'update'])->name('portal.beneficiaries.update');
    Route::delete('/beneficiari/{beneficiary}', [BeneficiaryController::class, 'destroy'])->name('portal.beneficiaries.destroy');
    Route::get('/beneficiari/cerca', [BeneficiaryController::class, 'search'])->name('portal.beneficiaries.search');
    Route::get('/beneficiari/cerca-salvati', [BeneficiaryController::class, 'searchSaved'])->name('portal.beneficiaries.search-saved');
    Route::post('/profilo/2fa/inizia', [TwoFactorController::class, 'startSetup'])->name('portal.2fa.start');
    Route::post('/profilo/2fa/conferma', [TwoFactorController::class, 'confirmSetup'])->name('portal.2fa.confirm');
    Route::post('/profilo/2fa/disattiva', [TwoFactorController::class, 'disable'])->name('portal.2fa.disable')->middleware('step.up');
    Route::get('/profilo/2fa/codici-recupero', [TwoFactorController::class, 'showRecoveryCodes'])->name('portal.2fa.recovery-codes');
    Route::post('/profilo/2fa/rigenera-codici', [TwoFactorController::class, 'regenerateCodes'])->name('portal.2fa.regenerate-codes')->middleware('step.up');

    Route::get('/conti', [AccountController::class, 'structure'])->name('portal.accounts.structure');
    Route::post('/conti/sottoconti', [AccountController::class, 'storeSubaccount'])->name('portal.accounts.subaccounts.store');
    Route::post('/conti/sottoconti/{subaccount}/budget', [AccountController::class, 'topUpSubaccount'])->name('portal.accounts.subaccounts.budget');
    Route::post('/conti/sottoconti/{subaccount}/limits', [AccountController::class, 'updateSubaccountLimits'])->name('portal.accounts.subaccounts.limits');
    Route::post('/conti/sottoconti/{subaccount}/status', [AccountController::class, 'updateSubaccountStatus'])->name('portal.accounts.subaccounts.status');
    Route::post('/conti/sottoconti/{subaccount}/invita', [AccountController::class, 'inviteManager'])->name('portal.accounts.subaccounts.invite');
    Route::delete('/conti/sottoconti/{subaccount}/inviti/{invitation}', [AccountController::class, 'cancelInvitation'])->name('portal.accounts.subaccounts.invite.cancel');
    Route::delete('/conti/sottoconti/{subaccount}/gestori/{manager}', [AccountController::class, 'revokeManager'])->name('portal.accounts.subaccounts.revoke');
    Route::post('/conti/sottoconti/{subaccount}/accetta', [AccountController::class, 'acceptAssignment'])->name('portal.accounts.subaccounts.accept');
    Route::post('/conti/sottoconti/{subaccount}/rifiuta', [AccountController::class, 'declineAssignment'])->name('portal.accounts.subaccounts.decline');

    // Richieste limite / sforamento sottoconti
    Route::post('/conti/sottoconti/{subaccount}/richieste-limite', [SubAccountLimitRequestController::class, 'store'])->name('portal.accounts.subaccounts.limit-request.store');
    Route::post('/conti/sottoconti/richieste-limite/{limitRequest}/approva', [SubAccountLimitRequestController::class, 'approve'])->name('portal.accounts.subaccounts.limit-request.approve');
    Route::post('/conti/sottoconti/richieste-limite/{limitRequest}/rifiuta', [SubAccountLimitRequestController::class, 'reject'])->name('portal.accounts.subaccounts.limit-request.reject');
    Route::post('/conto/switch', [PortalController::class, 'switchAccount'])->name('portal.switch-account');

    Route::get('/azienda/profilo', [PortalController::class, 'editProfile'])->name('portal.profile.edit');
    Route::post('/azienda/profilo', [PortalController::class, 'updateProfile'])->name('portal.profile.update');

    Route::get('/aziende', [PortalController::class, 'companies'])->name('portal.companies');
    Route::get('/aziende/{company:slug}', [PortalController::class, 'showCompany'])->name('portal.companies.show');

    Route::get('/notifiche', [PortalController::class, 'notifications'])->name('portal.notifications');
    Route::post('/notifiche/{id}/letto', [PortalController::class, 'markNotificationRead'])->name('portal.notifications.read');
    Route::post('/notifiche/letti-tutti', [PortalController::class, 'markAllNotificationsRead'])->name('portal.notifications.read-all');

    Route::get('/annunci', [AnnouncementController::class, 'index'])->name('portal.announcements');
    Route::get('/annunci/crea', [AnnouncementController::class, 'create'])->name('portal.announcements.create');
    Route::post('/annunci', [AnnouncementController::class, 'store'])->name('portal.announcements.store');
    Route::get('/annunci/{announcement}', [AnnouncementController::class, 'show'])->name('portal.announcements.show');
    Route::get('/annunci/{announcement}/modifica', [AnnouncementController::class, 'edit'])->name('portal.announcements.edit');
    Route::put('/annunci/{announcement}', [AnnouncementController::class, 'update'])->name('portal.announcements.update');
    Route::delete('/annunci/{announcement}', [AnnouncementController::class, 'destroy'])->name('portal.announcements.destroy');
    Route::post('/annunci/{announcement}/rispondi', [AnnouncementController::class, 'reply'])->name('portal.announcements.reply');

    Route::get('/shop', [ListingController::class, 'index'])->name('portal.shop');
    Route::get('/shop/crea', [ListingController::class, 'create'])->name('portal.shop.create');
    Route::post('/shop', [ListingController::class, 'store'])->name('portal.shop.store');
    Route::get('/shop/{listing}', [ListingController::class, 'show'])->name('portal.shop.show');
    Route::get('/shop/{listing}/modifica', [ListingController::class, 'edit'])->name('portal.shop.edit');
    Route::put('/shop/{listing}', [ListingController::class, 'update'])->name('portal.shop.update');
    Route::delete('/shop/{listing}', [ListingController::class, 'destroy'])->name('portal.shop.destroy');
    Route::delete('/shop/{listing}/immagini', [ListingController::class, 'destroyImage'])->name('portal.shop.image.destroy');

    Route::get('/movimenti', [PortalController::class, 'movements'])->name('portal.movements');
    Route::get('/movimenti/export-csv', [PortalController::class, 'exportMovementsCsv'])->name('portal.movements.export-csv');
    Route::get('/movimenti/{uuid}/ricevuta', [ReceiptController::class, 'download'])->name('portal.receipt.download');
    Route::get('/prima-nota/export', [PortalController::class, 'exportPrimaNota'])->name('portal.prima-nota.export');
    Route::get('/movimenti/{transfer}/rimborso', [PortalController::class, 'refundForm'])->name('portal.refund.form');
    Route::post('/movimenti/{transfer}/rimborso', [PortalController::class, 'refundSubmit'])->name('portal.refund.submit');
    Route::get('/nota-di-credito', [PortalController::class, 'creditNoteForm'])->name('portal.credit-note.form');
    Route::get('/nota-di-credito/{transfer}', [PortalController::class, 'creditNoteForm'])->name('portal.credit-note.from-transfer');
    Route::post('/nota-di-credito', [PortalController::class, 'creditNoteSubmit'])->name('portal.credit-note.submit');

    // Piani rateali
    Route::get('/rate', [PaymentPlanController::class, 'index'])->name('portal.payment-plans.index');
    Route::get('/rate/crea', [PaymentPlanController::class, 'create'])->name('portal.payment-plans.create');
    Route::post('/rate', [PaymentPlanController::class, 'store'])->name('portal.payment-plans.store')->middleware('throttle:financial_ops');
    Route::get('/rate/{paymentPlan}', [PaymentPlanController::class, 'show'])->name('portal.payment-plans.show');
    Route::post('/rate/{paymentPlan}/annulla', [PaymentPlanController::class, 'cancel'])->name('portal.payment-plans.cancel');
    Route::post('/rate/{paymentPlan}/approva', [PaymentPlanController::class, 'approve'])->name('portal.payment-plans.approve');
    Route::post('/rate/{paymentPlan}/rifiuta', [PaymentPlanController::class, 'reject'])->name('portal.payment-plans.reject');

    // Link di pagamento condivisibili
    Route::get('/link-pagamento', [PaymentLinkController::class, 'index'])->name('portal.payment-links.index');
    Route::get('/link-pagamento/crea', [PaymentLinkController::class, 'create'])->name('portal.payment-links.create');
    Route::post('/link-pagamento', [PaymentLinkController::class, 'store'])->name('portal.payment-links.store');
    Route::get('/link-pagamento/{token}', [PaymentLinkController::class, 'show'])->name('portal.payment-links.show');
    Route::post('/link-pagamento/{token}/annulla', [PaymentLinkController::class, 'cancel'])->name('portal.payment-links.cancel');
    Route::get('/richieste', [PortalController::class, 'paymentRequests'])->name('portal.requests');

    // Compensazione crediti incrociati
    Route::get('/fido', [PortalController::class, 'creditLimitView'])->name('portal.fido');
    Route::post('/fido/richiedi', [PortalController::class, 'storeFidoRequest'])->name('portal.fido.request');
    Route::get('/compensazione', [NettingController::class, 'index'])->name('portal.netting.index');
    Route::get('/compensazione/nuova', [NettingController::class, 'create'])->name('portal.netting.create');
    Route::get('/compensazione/trasferimenti', [NettingController::class, 'loadTransfers'])->name('portal.netting.load-transfers');
    Route::post('/compensazione', [NettingController::class, 'store'])->name('portal.netting.store')->middleware('throttle:financial_ops');
    Route::get('/compensazione/{nettingProposal}', [NettingController::class, 'show'])->name('portal.netting.show');
    Route::post('/compensazione/{nettingProposal}/accetta', [NettingController::class, 'accept'])->name('portal.netting.accept');
    Route::post('/compensazione/{nettingProposal}/rifiuta', [NettingController::class, 'reject'])->name('portal.netting.reject');


    Route::get('/paga', [PortalController::class, 'payForm'])->name('portal.pay.form');
    Route::post('/paga', [PortalController::class, 'paySubmit'])->name('portal.pay.submit')->middleware('throttle:payments');
    Route::post('/pagamenti/pausa', [PortalController::class, 'togglePaymentsPause'])->name('portal.payments.toggle-pause');

    Route::get('/incassa', [PortalController::class, 'receiveForm'])->name('portal.receive.form');
    Route::post('/incassa', [PortalController::class, 'receiveSubmit'])->name('portal.receive.submit')->middleware('throttle:payments');
    Route::post('/incassa/richieste/{transfer}/conferma', [PortalController::class, 'confirmReceiveRequest'])->name('portal.receive.requests.confirm');
    Route::post('/incassa/richieste/{transfer}/rifiuta', [PortalController::class, 'rejectReceiveRequest'])->name('portal.receive.requests.reject');

    Route::get('/kyc', [KycController::class, 'show'])->name('portal.kyc');
    Route::post('/kyc/upload', [KycController::class, 'upload'])->name('portal.kyc.upload');
    Route::get('/kyc/documenti/{kycDocument}/download', [KycController::class, 'download'])->name('portal.kyc.download');

    // Carta virtuale KMoney + pagamento da QR
    Route::get('/carta', [CardController::class, 'show'])->name('portal.card');
    Route::post('/carta/blocca', [CardController::class, 'block'])->name('portal.card.block');
    Route::post('/carta/sblocca', [CardController::class, 'unblock'])->name('portal.card.unblock');
    Route::get('/carta/pdf', [CardController::class, 'downloadPdf'])->name('portal.card.pdf');
    Route::get('/paga/qr/{accountNumber}', [CardController::class, 'payQr'])->name('portal.pay.qr');

    // QR Incasso dinamico
    Route::get('/incassa/qr', [IncassoQrController::class, 'form'])->name('portal.incasso-qr.form');
    Route::post('/incassa/qr', [IncassoQrController::class, 'store'])->name('portal.incasso-qr.store')->middleware('throttle:incasso');
    Route::get('/incassa/qr/{token}', [IncassoQrController::class, 'show'])->name('portal.incasso-qr.show');
    Route::get('/incassa/qr/{token}/stato', [IncassoQrController::class, 'status'])->name('portal.incasso-qr.status');
    Route::post('/incassa/qr/{token}/annulla', [IncassoQrController::class, 'cancel'])->name('portal.incasso-qr.cancel');
    // Incasso NFC (Web NFC API — smartphone-to-smartphone)
    Route::get('/incassa/nfc', [NfcPaymentController::class, 'form'])->name('portal.incasso-nfc.form');
    Route::post('/incassa/nfc', [NfcPaymentController::class, 'store'])->name('portal.incasso-nfc.store')->middleware('throttle:incasso');
    Route::get('/incassa/nfc/{token}', [NfcPaymentController::class, 'show'])->name('portal.incasso-nfc.show');
    Route::get('/incassa/nfc/{token}/stato', [NfcPaymentController::class, 'status'])->name('portal.incasso-nfc.status');
    Route::post('/incassa/nfc/{token}/annulla', [NfcPaymentController::class, 'cancel'])->name('portal.incasso-nfc.cancel');

    // Pagamento Card NFC fisica (Opzione A) — identify/request/status richiedono auth
    Route::post('/nfc/card/identify', [NfcCardPaymentController::class, 'identify'])->name('nfc.card.identify');
    Route::post('/nfc/card/request', [NfcCardPaymentController::class, 'createRequest'])->name('nfc.card.request')->middleware('throttle:incasso');
    Route::get('/nfc/card/status/{nonce}', [NfcCardPaymentController::class, 'status'])->name('nfc.card.status');

    // Incasso Sonic (Web Audio API — smartphone-to-smartphone via suono)
    Route::get('/incassa/sonic', [SonicPaymentController::class, 'form'])->name('portal.incasso-sonic.form');
    Route::post('/incassa/sonic', [SonicPaymentController::class, 'store'])->name('portal.incasso-sonic.store')->middleware('throttle:incasso');
    Route::get('/incassa/sonic/{token}', [SonicPaymentController::class, 'show'])->name('portal.incasso-sonic.show');
    Route::get('/incassa/sonic/{token}/stato', [SonicPaymentController::class, 'status'])->name('portal.incasso-sonic.status');
    Route::post('/incassa/sonic/{token}/annulla', [SonicPaymentController::class, 'cancel'])->name('portal.incasso-sonic.cancel');

    // Paga Sonic (lato cliente/decodificatore)
    Route::get('/paga/sonic', [SonicPaymentController::class, 'receiveForm'])->name('portal.paga-sonic.form');
    Route::post('/paga/sonic/verifica', [SonicPaymentController::class, 'verify'])->name('portal.paga-sonic.verify')->middleware('throttle:payments');

    // W3C Payment Request API — handler window e pagamento
    // La finestra /paga/handler viene aperta dal browser in un payment sheet
    Route::get('/paga/handler', [PaymentHandlerController::class, 'window'])->name('portal.payment-handler.window');
    Route::post('/paga/handler/pay', [PaymentHandlerController::class, 'pay'])->name('portal.payment-handler.pay')->middleware('throttle:payments');

    // Pagamento con codice numerico 6 cifre
    Route::get('/incassa/codice', [CodePaymentController::class, 'form'])->name('portal.incasso-codice.form');
    Route::post('/incassa/codice', [CodePaymentController::class, 'store'])->name('portal.incasso-codice.store')->middleware('throttle:incasso');
    Route::get('/incassa/codice/{token}', [CodePaymentController::class, 'show'])->name('portal.incasso-codice.show');
    Route::get('/incassa/codice/{token}/stato', [CodePaymentController::class, 'status'])->name('portal.incasso-codice.status');
    Route::post('/incassa/codice/{token}/annulla', [CodePaymentController::class, 'cancel'])->name('portal.incasso-codice.cancel');
    Route::get('/paga/codice', [CodePaymentController::class, 'receiveForm'])->name('portal.paga-codice.form');
    Route::post('/paga/codice/verifica', [CodePaymentController::class, 'verify'])->name('portal.paga-codice.verify')->middleware('throttle:payments');

    // KY Wallet — carta virtuale + hub pagamenti
    Route::get('/wallet', [WalletController::class, 'index'])->name('portal.wallet');


    // Pagamenti programmati
    Route::get('/pagamenti-programmati', [ScheduledPaymentController::class, 'index'])->name('portal.scheduled-payments.index');
    Route::get('/pagamenti-programmati/nuovo', [ScheduledPaymentController::class, 'create'])->name('portal.scheduled-payments.create');
    Route::post('/pagamenti-programmati', [ScheduledPaymentController::class, 'store'])->name('portal.scheduled-payments.store')->middleware('throttle:financial_ops');
    Route::post('/pagamenti-programmati/gruppo/{group}/annulla', [ScheduledPaymentController::class, 'cancelGroup'])->name('portal.scheduled-payments.cancel-group');
    Route::post('/pagamenti-programmati/{scheduledPayment}/riprova', [ScheduledPaymentController::class, 'retry'])->name('portal.scheduled-payments.retry')->middleware('throttle:financial_ops');
    Route::get('/pagamenti-programmati/{scheduledPayment}', [ScheduledPaymentController::class, 'show'])->name('portal.scheduled-payments.show');
    Route::post('/pagamenti-programmati/{scheduledPayment}/annulla', [ScheduledPaymentController::class, 'cancel'])->name('portal.scheduled-payments.cancel');

    // Richieste di pagamento testuali — index rediretto alla pagina unificata
    Route::get('/richieste-pagamento', fn() => redirect()->route('portal.requests', ['tab' => 'formali']))->name('portal.text-requests.index');
    Route::get('/richieste-pagamento/nuova', [TextPaymentRequestController::class, 'create'])->name('portal.text-requests.create');
    Route::post('/richieste-pagamento', [TextPaymentRequestController::class, 'store'])->name('portal.text-requests.store')->middleware('throttle:financial_ops');
    Route::get('/richieste-pagamento/{textPaymentRequest}', [TextPaymentRequestController::class, 'show'])->name('portal.text-requests.show');
    Route::post('/richieste-pagamento/{textPaymentRequest}/approva', [TextPaymentRequestController::class, 'approve'])->name('portal.text-requests.approve')->middleware('throttle:payments');
    Route::post('/richieste-pagamento/{textPaymentRequest}/rifiuta', [TextPaymentRequestController::class, 'reject'])->name('portal.text-requests.reject');
    Route::post('/richieste-pagamento/{textPaymentRequest}/annulla', [TextPaymentRequestController::class, 'cancel'])->name('portal.text-requests.cancel');

    // Pagamento da QR (lato pagatore)
    Route::get('/pay/{token}', [PaymentRequestController::class, 'show'])->name('portal.pay-request.show');
    Route::post('/pay/{token}', [PaymentRequestController::class, 'pay'])->name('portal.pay-request.pay')->middleware('throttle:payments');

    // Estratto conto PDF
    Route::get('/estratto-conto', [StatementController::class, 'show'])->name('portal.statement');
    Route::get('/estratto-conto/download', [StatementController::class, 'download'])->name('portal.statement.download');

    // Webhook (integrazioni esterne)
    Route::get('/webhook', [WebhookController::class, 'index'])->name('portal.webhooks.index');
    Route::get('/webhook/nuovo', [WebhookController::class, 'create'])->name('portal.webhooks.create');
    Route::post('/webhook', [WebhookController::class, 'store'])->name('portal.webhooks.store');
    Route::get('/webhook/{webhook}', [WebhookController::class, 'show'])->name('portal.webhooks.show');
    Route::post('/webhook/{webhook}/toggle', [WebhookController::class, 'toggle'])->name('portal.webhooks.toggle');
    Route::post('/webhook/{webhook}/test', [WebhookController::class, 'test'])->name('portal.webhooks.test');
    Route::delete('/webhook/{webhook}', [WebhookController::class, 'destroy'])->name('portal.webhooks.destroy');

    // Token API
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('portal.api-tokens.index');
    Route::get('/api-tokens/nuovo', [ApiTokenController::class, 'create'])->name('portal.api-tokens.create');
    Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('portal.api-tokens.store')->middleware('step.up');
    Route::get('/api-tokens/{apiToken}', [ApiTokenController::class, 'show'])->name('portal.api-tokens.show');
    Route::delete('/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('portal.api-tokens.destroy')->middleware('step.up');

    // API Documentation
    Route::get('/docs/api', [DocsController::class, 'apiDocs'])->name('portal.docs-api');

    // Broker / operatore
    Route::get('/broker', [BrokerController::class, 'dashboard'])->name('broker.dashboard');
    Route::get('/broker/clienti/{company}', [BrokerController::class, 'showClient'])->name('broker.clients.show');
    Route::get('/broker/clienti/{company}/paga', [BrokerController::class, 'payForm'])->name('broker.pay.form');
    Route::post('/broker/clienti/{company}/paga', [BrokerController::class, 'paySubmit'])->name('broker.pay.submit')->middleware('throttle:payments');


    // KYCard — ricarica KMoney (Carta, PayPal, Bonifico)
    Route::get('/ricarica', [KyCardController::class, 'index'])->name('portal.ky-cards.index');
    Route::get('/ricarica/storico', [KyCardController::class, 'storico'])->name('portal.ky-cards.storico');
    Route::get('/ricarica/{kyCard}', [KyCardController::class, 'checkout'])->name('portal.ky-cards.checkout');
    Route::post('/ricarica/{kyCard}/stripe', [KyCardController::class, 'stripeCheckout'])->name('portal.ky-cards.stripe-checkout')->middleware('throttle:10,1');
    Route::post('/ricarica/{kyCard}/paypal/create-order', [KyCardController::class, 'paypalCreateOrder'])->name('portal.ky-cards.paypal-create-order')->middleware('throttle:10,1');
    Route::get('/ricarica/paypal/capture/{purchase}', [KyCardController::class, 'paypalCapture'])->name('portal.ky-cards.paypal-capture');
    Route::post('/ricarica/{kyCard}/bonifico', [KyCardController::class, 'bankTransfer'])->name('portal.ky-cards.bank-transfer');
    Route::get('/ricarica/success/{purchase}', [KyCardController::class, 'success'])->name('portal.ky-cards.success');

    // Admin Settori
    Route::get('/admin/settori', [AdminSectorController::class, 'index'])->name('admin.sectors.index');
    Route::post('/admin/settori', [AdminSectorController::class, 'store'])->name('admin.sectors.store');
    Route::put('/admin/settori/{sector}', [AdminSectorController::class, 'update'])->name('admin.sectors.update');
    Route::patch('/admin/settori/{sector}/toggle', [AdminSectorController::class, 'toggle'])->name('admin.sectors.toggle');
    Route::delete('/admin/settori/{sector}', [AdminSectorController::class, 'destroy'])->name('admin.sectors.destroy');

    // Admin KYCard CRUD
    Route::get('/admin/ky-cards', [AdminKyCardController::class, 'index'])->name('admin.ky-cards.index');
    Route::get('/admin/ky-cards/create', [AdminKyCardController::class, 'create'])->name('admin.ky-cards.create');
    Route::post('/admin/ky-cards', [AdminKyCardController::class, 'store'])->name('admin.ky-cards.store');
    Route::get('/admin/ky-cards/{kyCard}/edit', [AdminKyCardController::class, 'edit'])->name('admin.ky-cards.edit');
    Route::put('/admin/ky-cards/{kyCard}', [AdminKyCardController::class, 'update'])->name('admin.ky-cards.update');
    Route::delete('/admin/ky-cards/{kyCard}', [AdminKyCardController::class, 'destroy'])->name('admin.ky-cards.destroy');
    Route::patch('/admin/ky-cards/{kyCard}/toggle', [AdminKyCardController::class, 'toggle'])->name('admin.ky-cards.toggle');
    Route::get('/admin/ky-cards/bonifici', [AdminKyCardController::class, 'pendingTransfers'])->name('admin.ky-cards.pending-transfers');
    Route::post('/admin/ky-cards/bonifici/{purchase}/confirm', [KyCardController::class, 'adminConfirmBankTransfer'])->name('admin.ky-cards.confirm-transfer');
    Route::post('/admin/ky-cards/bonifici/{purchase}/reject', [KyCardController::class, 'adminRejectBankTransfer'])->name('admin.ky-cards.reject-transfer');
    Route::post('/admin/ky-cards/acquisti/{purchase}/retry', [KyCardController::class, 'adminRetryCredit'])->name('admin.ky-cards.retry-credit');

    // -- Card NFC fisiche (Admin) -----------------------------------------
    Route::get('/admin/nfc-cards', [AdminNfcCardController::class, 'index'])->name('admin.nfc-cards.index');
    Route::get('/admin/nfc-cards/create', [AdminNfcCardController::class, 'create'])->name('admin.nfc-cards.create');
    Route::post('/admin/nfc-cards', [AdminNfcCardController::class, 'store'])->name('admin.nfc-cards.store');
    Route::get('/admin/nfc-cards/{nfcCard}', [AdminNfcCardController::class, 'show'])->name('admin.nfc-cards.show');
    Route::post('/admin/nfc-cards/{nfcCard}/mark-issued', [AdminNfcCardController::class, 'markIssued'])->name('admin.nfc-cards.mark-issued');
    Route::post('/admin/nfc-cards/{nfcCard}/mark-delivered', [AdminNfcCardController::class, 'markDelivered'])->name('admin.nfc-cards.mark-delivered');
    Route::post('/admin/nfc-cards/{nfcCard}/revoke', [AdminNfcCardController::class, 'revoke'])->name('admin.nfc-cards.revoke');

    Route::get('/admin/ky-cards/ordini', [KyCardController::class, 'adminOrders'])->name('admin.ky-cards.orders');

        Route::get('/admin', [AdminController::class, 'dashboard'])->name('admin.dashboard');

    Route::get('/admin/users', [AdminController::class, 'users'])->name('admin.users.index');
    Route::post('/admin/users', [AdminController::class, 'storeUser'])->name('admin.users.store');
    Route::post('/admin/users/verifica-tutti', [AdminController::class, 'verifyAllUsers'])->name('admin.users.verify-all');
    Route::get('/admin/users/{user}', [AdminController::class, 'showUser'])->name('admin.users.show');
    Route::post('/admin/users/{user}', [AdminController::class, 'updateUser'])->name('admin.users.update');
    Route::post('/admin/users/{user}/verifica-email', [AdminController::class, 'verifyUserEmail'])->name('admin.users.verify-email');
    Route::post('/admin/users/{user}/password', [AdminController::class, 'changePasswordUser'])->name('admin.users.password');
    Route::delete('/admin/users/{user}/sessioni/{sessionId}', [AdminController::class, 'terminateUserSession'])->name('admin.users.sessions.terminate');
    Route::delete('/admin/users/{user}/sessioni', [AdminController::class, 'terminateAllUserSessions'])->name('admin.users.sessions.terminate-all');

    Route::get('/admin/roles', [AdminController::class, 'roles'])->name('admin.roles.index');
    Route::post('/admin/roles', [AdminController::class, 'storeRole'])->name('admin.roles.store');
    Route::post('/admin/roles/{role}', [AdminController::class, 'updateRole'])->name('admin.roles.update');

    Route::get('/admin/companies', [AdminController::class, 'companies'])->name('admin.companies.index');
    Route::get('/admin/companies/{company}', [AdminController::class, 'showCompany'])->name('admin.companies.show');
    Route::post('/admin/companies/{company}/broker', [AdminController::class, 'assignBroker'])->name('admin.companies.broker');
    Route::post('/admin/companies/{company}/credit-limit', [AdminController::class, 'setCreditLimit'])->name('admin.companies.credit-limit');
    Route::post('/admin/companies/{company}/max-balance', [AdminController::class, 'setMaxBalance'])->name('admin.companies.max-balance');
    Route::get('/admin/richieste-fido', [AdminController::class, 'creditLimitRequests'])->name('admin.credit-requests.index');
    Route::post('/admin/richieste-fido/{creditRequest}/approve', [AdminController::class, 'approveCreditRequest'])->name('admin.credit-requests.approve');
    Route::post('/admin/richieste-fido/{creditRequest}/reject', [AdminController::class, 'rejectCreditRequest'])->name('admin.credit-requests.reject');
    Route::post('/admin/companies/{company}/suspend', [AdminController::class, 'suspendCompany'])->name('admin.companies.suspend');
    Route::post('/admin/companies/{company}/unsuspend', [AdminController::class, 'unsuspendCompany'])->name('admin.companies.unsuspend');
    Route::post('/admin/companies/{company}/activate', [AdminController::class, 'activateCompany'])->name('admin.companies.activate');
    Route::post('/admin/companies/{company}/deactivate', [AdminController::class, 'deactivateCompany'])->name('admin.companies.deactivate');
    Route::post('/admin/companies/{company}/plan', [AdminController::class, 'updatePlan'])->name('admin.companies.plan');
    Route::post('/admin/payment-plans/{plan}/cancel', [AdminController::class, 'cancelPaymentPlan'])->name('admin.payment-plans.cancel');
    Route::post('/admin/netting/{proposal}/cancel', [AdminController::class, 'cancelNettingProposal'])->name('admin.netting.cancel');
    Route::get('/admin/webhooks/deliveries', [AdminController::class, 'webhookDeliveries'])->name('admin.webhook-deliveries');
    Route::post('/admin/webhooks/deliveries/{delivery}/retry', [AdminController::class, 'retryWebhook'])->name('admin.webhook-deliveries.retry');

    Route::get('/admin/accounts', [AdminController::class, 'accounts'])->name('admin.accounts.index');
    Route::get('/admin/accounts/{account}', [AdminController::class, 'showAccount'])->name('admin.accounts.show');
    Route::post('/admin/accounts/{account}', [AdminController::class, 'updateAccount'])->name('admin.accounts.update');
    Route::get('/admin/accounts/{account}/statement', [StatementController::class, 'adminDownload'])->name('admin.accounts.statement');

    // Emissione KY sovrana (solo super admin)
    Route::get('/admin/emissione-ky', [AdminController::class, 'emitKyForm'])->name('admin.ky.emit');
    Route::post('/admin/emissione-ky', [AdminController::class, 'emitKy'])->name('admin.ky.emit.submit');

    Route::get('/admin/report', [AdminController::class, 'report'])->name('admin.report');
    Route::get('/admin/audit', [AdminController::class, 'auditLog'])->name('admin.audit');
    Route::get('/admin/audit/export-csv', [AdminController::class, 'exportAuditCsv'])->name('admin.audit.export-csv');
    Route::get('/admin/analytics', [AdminController::class, 'analytics'])->name('admin.analytics');
    Route::get('/admin/branding', [AdminController::class, 'branding'])->name('admin.branding');
Route::get('/admin/contratto',   [AdminController::class, 'contractSettings'])->name('admin.contract-settings');
Route::patch('/admin/contratto', [AdminController::class, 'contractSettingsUpdate'])->name('admin.contract-settings.update');
Route::post('/admin/contratto/testo', [AdminController::class, 'contractTextUpdate'])->name('admin.contract-text.update');
Route::get('/admin/contratto/firme',             [AdminController::class, 'contractSignatures'])->name('admin.contract-signatures');
Route::get('/admin/contratto/firme/export',      [AdminController::class, 'contractSignaturesExport'])->name('admin.contract-signatures.export');
Route::get('/admin/contratto/firme/{signature}', [AdminController::class, 'contractSignatureShow'])->name('admin.contract-signatures.show');
Route::get('/admin/contratto/firme/{signature}/pdf', [AdminController::class, 'contractSignatureExportSingle'])->name('admin.contract-signatures.export-single');
    Route::patch('/admin/branding', [AdminController::class, 'brandingUpdate'])->name('admin.branding.update');

    // Web Push subscriptions
    Route::get('/push/vapid-key', [PushSubscriptionController::class, 'vapidKey'])->name('push.vapid-key');
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'subscribe'])->name('push.subscribe');
    Route::delete('/push/subscribe', [PushSubscriptionController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::get('/admin/report/export-csv', [AdminController::class, 'exportCsv'])->name('admin.report.export-csv');
    Route::get('/admin/transfers', [AdminController::class, 'transfers'])->name('admin.transfers.index');
    Route::post('/admin/transfers/{transfer}/refund', [AdminController::class, 'refundTransfer'])->name('admin.transfers.refund');

    Route::get('/admin/limits', [AdminController::class, 'limits'])->name('admin.limits.index');
    Route::post('/admin/limits', [AdminController::class, 'updateLimitDefaults'])->name('admin.limits.update');

    Route::get('/admin/kyc', [KycController::class, 'adminIndex'])->name('admin.kyc.index');
    Route::get('/admin/kyc/{company}', [KycController::class, 'adminShow'])->name('admin.kyc.show');
    Route::post('/admin/kyc/{company}/approve', [KycController::class, 'approve'])->name('admin.kyc.approve');
    Route::post('/admin/kyc/{company}/reject', [KycController::class, 'reject'])->name('admin.kyc.reject');
    Route::post('/admin/kyc/{company}/request-docs', [KycController::class, 'requestMoreDocs'])->name('admin.kyc.request-docs');

    Route::get('/admin/listings', [ListingController::class, 'adminIndex'])->name('admin.listings.index');
    Route::post('/admin/listings/{listing}/status', [ListingController::class, 'adminUpdateStatus'])->name('admin.listings.status');

    Route::get('/admin/announcements', [AnnouncementController::class, 'adminIndex'])->name('admin.announcements.index');
    Route::post('/admin/announcements/{announcement}/status', [AnnouncementController::class, 'adminUpdateStatus'])->name('admin.announcements.status');

    // Cashback rules (admin)
    Route::get('/admin/cashback', [CashbackRuleController::class, 'index'])->name('admin.cashback.index');
    Route::get('/admin/cashback/create', [CashbackRuleController::class, 'create'])->name('admin.cashback.create');
    Route::post('/admin/cashback', [CashbackRuleController::class, 'store'])->name('admin.cashback.store');
    Route::get('/admin/cashback/{cashbackRule}/edit', [CashbackRuleController::class, 'edit'])->name('admin.cashback.edit');
    Route::put('/admin/cashback/{cashbackRule}', [CashbackRuleController::class, 'update'])->name('admin.cashback.update');
    Route::delete('/admin/cashback/{cashbackRule}', [CashbackRuleController::class, 'destroy'])->name('admin.cashback.destroy');
    Route::post('/admin/cashback/{cashbackRule}/toggle', [CashbackRuleController::class, 'toggleActive'])->name('admin.cashback.toggle');

    // ── Commissioni transazioni (admin) ───────────────────────────────────────
    Route::get('/admin/fees', [AdminFeeController::class, 'index'])->name('admin.fees.index');
    Route::get('/admin/fees/create', [AdminFeeController::class, 'create'])->name('admin.fees.create');
    Route::post('/admin/fees', [AdminFeeController::class, 'store'])->name('admin.fees.store');
    Route::get('/admin/fees/{fee}/edit', [AdminFeeController::class, 'edit'])->name('admin.fees.edit');
    Route::put('/admin/fees/{fee}', [AdminFeeController::class, 'update'])->name('admin.fees.update');
    Route::delete('/admin/fees/{fee}', [AdminFeeController::class, 'destroy'])->name('admin.fees.destroy');
    Route::post('/admin/fees/{fee}/toggle', [AdminFeeController::class, 'toggle'])->name('admin.fees.toggle');

    // ── Comunicazione massiva (admin) ─────────────────────────────────────────
    Route::get('/admin/broadcast', [AdminBroadcastController::class, 'index'])->name('admin.broadcast.index');
    Route::get('/admin/broadcast/preview', [AdminBroadcastController::class, 'preview'])->name('admin.broadcast.preview');
    Route::post('/admin/broadcast', [AdminBroadcastController::class, 'send'])->name('admin.broadcast.send');

    // ── Messaggi assistenza (admin) ───────────────────────────────────────────
    Route::get('/admin/support', [AdminController::class, 'supportMessages'])->name('admin.support.index');
    Route::post('/admin/support/{message}/resolve', [AdminController::class, 'resolveSupport'])->name('admin.support.resolve');

    // ── Cache (admin) ─────────────────────────────────────────────────────────
    Route::post('/admin/cache/clear', [AdminController::class, 'clearCache'])->name('admin.cache.clear');

    // ── Visibilità menu utenti (admin) ────────────────────────────────────────
    Route::get('/admin/menu-visibility',          [\App\Http\Controllers\Admin\AdminMenuVisibilityController::class, 'index'])  ->name('admin.menu-visibility.index');
    Route::post('/admin/menu-visibility',         [\App\Http\Controllers\Admin\AdminMenuVisibilityController::class, 'store'])  ->name('admin.menu-visibility.store');
    Route::delete('/admin/menu-visibility',       [\App\Http\Controllers\Admin\AdminMenuVisibilityController::class, 'destroy'])->name('admin.menu-visibility.destroy');
    Route::delete('/admin/menu-visibility/{key}', [\App\Http\Controllers\Admin\AdminMenuVisibilityController::class, 'reset'])  ->name('admin.menu-visibility.reset');


    Route::post('/api/transfers', function (Request $request, TransferBookingService $bookingService) {
        $validated = $request->validate([
            'initiated_by'    => ['required', 'integer', 'exists:users,id'],
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'to_account_id'   => ['required', 'integer', 'exists:accounts,id'],
            'amount'          => ['required', 'integer', 'min:1'],
            'kind'            => ['nullable', 'string', 'max:50'],
            'description'     => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
        $validated['ip_address'] = $request->ip();
        try {
            $transfer = $bookingService->book($validated);
        } catch (\RuntimeException $exception) {
            $bookingService->recordRejectedAttempt($validated, $exception->getMessage());
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => [
            'id'                   => $transfer->id,
            'uuid'                 => $transfer->uuid,
            'amount'              => $transfer->amount,
            'status'              => $transfer->status,
            'booked_at'            => $transfer->booked_at,
        ]]);
    });

    // ── Grafico storico saldo (AJAX) ─────────────────────────────────────────
    Route::get('/dashboard/saldo-storico', [PortalController::class, 'balanceHistory'])->name('portal.balance-history');

    // ── Preferenze notifiche ─────────────────────────────────────────────────
    Route::get('/notifiche/preferenze', [NotificationPreferencesController::class, 'index'])->name('portal.notification-preferences');

    // Balance threshold alerts
    Route::get('/avvisi-saldo', [BalanceAlertController::class, 'index'])->name('portal.balance-alerts.index');
    Route::post('/avvisi-saldo', [BalanceAlertController::class, 'store'])->name('portal.balance-alerts.store')->middleware('throttle:10,1');
    Route::patch('/avvisi-saldo/{balanceAlert}/toggle', [BalanceAlertController::class, 'toggle'])->name('portal.balance-alerts.toggle');
    Route::delete('/avvisi-saldo/{balanceAlert}', [BalanceAlertController::class, 'destroy'])->name('portal.balance-alerts.destroy');
    Route::patch('/notifiche/preferenze', [NotificationPreferencesController::class, 'update'])->name('portal.notification-preferences.update');

    // ── Cambio email ─────────────────────────────────────────────────────────
    Route::get('/profilo/email', [EmailChangeController::class, 'show'])->name('portal.email-change');
    Route::post('/profilo/email', [EmailChangeController::class, 'request'])->name('portal.email-change.request')->middleware('throttle:5,1');
    Route::get('/profilo/email/verifica', [EmailChangeController::class, 'verifyForm'])->name('portal.email-change.verify-form');
    Route::post('/profilo/email/verifica', [EmailChangeController::class, 'verify'])->name('portal.email-change.verify')->middleware('throttle:10,1');
    Route::delete('/profilo/email', [EmailChangeController::class, 'cancel'])->name('portal.email-change.cancel');


    // -- Card NFC del cliente -----------------------------------------------
    Route::get('/nfc-cards', [NfcCardController::class, 'index'])->name('portal.nfc-cards.index');
    Route::get('/nfc-cards/{uuid}', [NfcCardController::class, 'show'])->name('portal.nfc-cards.show');
    Route::get('/nfc-cards/{uuid}/attiva', [NfcCardController::class, 'activateForm'])->name('portal.nfc-cards.activate');
    Route::post('/nfc-cards/{uuid}/attiva', [NfcCardController::class, 'activate'])->name('portal.nfc-cards.activate.post');
    Route::post('/nfc-cards/{uuid}/limiti', [NfcCardController::class, 'updateLimits'])->name('portal.nfc-cards.limits');
    Route::post('/nfc-cards/{uuid}/blocca', [NfcCardController::class, 'block'])->name('portal.nfc-cards.block');
    Route::post('/nfc-cards/{uuid}/sblocca', [NfcCardController::class, 'unblock'])->name('portal.nfc-cards.unblock');

});
