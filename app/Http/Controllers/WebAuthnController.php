<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WebAuthnCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

class WebAuthnController extends Controller
{
    // ── Helpers ────────────────────────────────────────────────────────────────

    /** RP ID = domain senza schema (es. "kmoney.test") */
    private function rpId(): string
    {
        return parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';
    }

    /**
     * RP ID "secured" (ammessi senza HTTPS) — serve per sviluppo in HTTP (localhost).
     * In produzione HTTPS questi sono vuoti: la libreria richiede HTTPS di default.
     */
    private function securedRpIds(): array
    {
        $scheme = parse_url(config('app.url'), PHP_URL_SCHEME);
        return $scheme === 'http' ? [$this->rpId()] : [];
    }

    /** Serializer Symfony per encode/decode WebAuthn */
    private function makeSerializer()
    {
        $mgr = AttestationStatementSupportManager::create();
        $mgr->add(NoneAttestationStatementSupport::create());

        return (new WebauthnSerializerFactory($mgr))->create();
    }

    /** Decodifica da base64url a stringa binaria */
    private function b64UrlDecode(string $data): string
    {
        $pad  = strlen($data) % 4;
        $data = strtr($data, '-_', '+/');
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($data);
    }

    /** Codifica da binario a base64url senza padding */
    private function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ── REGISTRAZIONE ─────────────────────────────────────────────────────────

    /**
     * POST /webauthn/register/options
     * Restituisce le PublicKeyCredentialCreationOptions (challenge + configurazione).
     * Richiede utente autenticato.
     */
    public function registerOptions(Request $request): JsonResponse
    {
        $user       = $request->user();
        $serializer = $this->makeSerializer();

        try {
            // Escludi credential già registrate per questo utente
            $excludeCredentials = $user->webAuthnCredentials()
                ->get()
                ->map(fn($c) => PublicKeyCredentialDescriptor::create(
                    'public-key',
                    $this->b64UrlDecode($c->credential_id),
                ))
                ->all();

            $options = PublicKeyCredentialCreationOptions::create(
                rp: PublicKeyCredentialRpEntity::create(
                    name: config('app.name'),
                    id:   $this->rpId(),
                ),
                user: PublicKeyCredentialUserEntity::create(
                    name:        $user->email,
                    id:          (string) $user->id,
                    displayName: $user->name . ($user->company ? ' — ' . $user->company->name : ' — Personale'),
                ),
                challenge:      random_bytes(32),
                pubKeyCredParams: [
                    PublicKeyCredentialParameters::create('public-key', -7),    // ES256
                    PublicKeyCredentialParameters::create('public-key', -257),  // RS256
                ],
                timeout:             60000,
                excludeCredentials:  $excludeCredentials,
                authenticatorSelection: AuthenticatorSelectionCriteria::create(
                    userVerification: 'required',
                ),
                attestation: 'none',
            );

            $json = $serializer->serialize($options, 'json');
            session(['webauthn_register_options' => $json]);

            return response()->json(json_decode($json, true));

        } catch (Throwable $e) {
            return response()->json(['error' => 'Errore opzioni registrazione: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /webauthn/register/verify
     * Verifica la risposta del dispositivo e salva la credential.
     */
    public function registerVerify(Request $request): JsonResponse
    {
        $user       = $request->user();
        $serializer = $this->makeSerializer();

        $optionsJson = session('webauthn_register_options');
        session()->forget('webauthn_register_options');

        if (! $optionsJson) {
            return response()->json(['error' => 'Sessione scaduta. Riprova.'], 422);
        }

        try {
            $options = $serializer->deserialize(
                $optionsJson,
                PublicKeyCredentialCreationOptions::class,
                'json',
            );

            $credential = $serializer->deserialize(
                json_encode($request->all()),
                PublicKeyCredential::class,
                'json',
            );

            $mgr       = AttestationStatementSupportManager::create();
            $mgr->add(NoneAttestationStatementSupport::create());
            $validator = AuthenticatorAttestationResponseValidator::create($mgr);

            /** @var PublicKeyCredentialSource $source */
            $source = $validator->check(
                $credential->response,
                $options,
                $this->rpId(),
                $this->securedRpIds(),
            );

            $credentialId = $this->b64UrlEncode($source->publicKeyCredentialId);

            if (WebAuthnCredential::where('credential_id', $credentialId)->exists()) {
                return response()->json(['error' => 'Dispositivo gia registrato.'], 422);
            }

            $accountLabel = $user->company ? $user->company->name : 'Personale';

            WebAuthnCredential::create([
                'user_id'           => $user->id,
                'credential_id'     => $credentialId,
                'credential_source' => $serializer->serialize($source, 'json'),
                'name'              => $request->input('name', $accountLabel . ' — ' . now()->format('d/m/Y')),
            ]);

            return response()->json(['success' => true]);

        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Registrazione fallita: ' . $e->getMessage(),
            ], 422);
        }
    }

    // ── AUTENTICAZIONE ────────────────────────────────────────────────────────

    /**
     * POST /webauthn/login/options
     * Restituisce le PublicKeyCredentialRequestOptions per l'utente con quella email.
     * Accessibile anche da guest.
     */
    public function loginOptions(Request $request): JsonResponse
    {
        // Email opzionale: se assente usa discoverable credentials (browser mostra passkey autonomamente)
        $request->validate(['email' => 'sometimes|nullable|email']);

        $user             = null;
        $allowCredentials = [];

        if ($request->filled('email')) {
            $user = User::where('email', $request->email)
                ->where('is_active', true)
                ->first();

            if ($user) {
                try {
                    if ($user->webAuthnCredentials()->doesntExist()) {
                        return response()->json(['error' => 'Nessuna impronta registrata per questo account.'], 404);
                    }
                    $allowCredentials = $user->webAuthnCredentials()
                        ->get()
                        ->map(fn($c) => PublicKeyCredentialDescriptor::create(
                            'public-key',
                            $this->b64UrlDecode($c->credential_id),
                        ))
                        ->all();
                } catch (Throwable $e) {
                    return response()->json(['error' => 'Errore del server: ' . $e->getMessage()], 500);
                }
            }
        }

        $serializer = $this->makeSerializer();

        try {
            $options = PublicKeyCredentialRequestOptions::create(
                challenge:        random_bytes(32),
                timeout:          60000,
                rpId:             $this->rpId(),
                allowCredentials: $allowCredentials,  // vuoto = discoverable
                userVerification: 'required',
            );

            $json = $serializer->serialize($options, 'json');
            session([
                'webauthn_login_options'  => $json,
                'webauthn_login_user_id'  => $user?->id,  // null se discoverable
            ]);

            return response()->json(json_decode($json, true));
        } catch (Throwable $e) {
            return response()->json(['error' => 'Errore nella generazione delle opzioni: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /webauthn/login/verify
     * Verifica l'assertion e crea la sessione autenticata.
     */
    public function loginVerify(Request $request): JsonResponse
    {
        $serializer  = $this->makeSerializer();
        $optionsJson = session('webauthn_login_options');
        $userId      = session('webauthn_login_user_id');
        session()->forget(['webauthn_login_options', 'webauthn_login_user_id']);

        if (! $optionsJson) {
            return response()->json(['error' => 'Sessione scaduta. Riprova.'], 422);
        }

        try {
            $options = $serializer->deserialize(
                $optionsJson,
                PublicKeyCredentialRequestOptions::class,
                'json',
            );

            $credential = $serializer->deserialize(
                json_encode($request->all()),
                PublicKeyCredential::class,
                'json',
            );

            // Discoverable flow: se userId non è in sessione, legge userHandle dalla risposta
            if (! $userId) {
                $userHandle = $credential->response->userHandle ?? null;
                if (! $userHandle) {
                    return response()->json(['error' => "Impossibile identificare l'utente."], 401);
                }
                // userHandle = binario di (string) $user->id, es. "\x31" = "1"
                $userId = (int) $userHandle;
            }

            // Trova la credential nel DB tramite id (base64url)
            $storedCred = WebAuthnCredential::where('credential_id', $this->b64UrlEncode($credential->rawId))
                ->where('user_id', $userId)
                ->first();

            if (! $storedCred) {
                return response()->json(['error' => 'Dispositivo non riconosciuto.'], 401);
            }

            $source = $serializer->deserialize(
                $storedCred->credential_source,
                PublicKeyCredentialSource::class,
                'json',
            );

            $validator     = AuthenticatorAssertionResponseValidator::create();
            $updatedSource = $validator->check(
                $source,
                $credential->response,
                $options,
                $this->rpId(),
                (string) $userId,
                $this->securedRpIds(),
            );

            // Aggiorna il contatore e la data ultimo uso
            $storedCred->update([
                'credential_source' => $serializer->serialize($updatedSource, 'json'),
                'last_used_at'      => now(),
            ]);

            // Autentica l'utente nella sessione Laravel
            $user = User::findOrFail($userId);
            Auth::login($user, false);
            $request->session()->regenerate();

            if ($user->canAccessBackoffice()) {
                $redirect = route('admin.dashboard');
            } else {
                // Rispetta eventuale URL intesa (es. /pay/{token} da QR non autenticato)
                $redirect = session()->pull('url.intended', route('portal.dashboard'));
            }

            return response()->json(['redirect' => $redirect]);

        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Autenticazione fallita: ' . $e->getMessage(),
            ], 401);
        }
    }

    // ── GESTIONE DISPOSITIVI ──────────────────────────────────────────────────

    /** GET /webauthn/credentials — lista dispositivi registrati */
    public function listCredentials(Request $request): JsonResponse
    {
        $list = $request->user()
            ->webAuthnCredentials()
            ->orderByDesc('last_used_at')
            ->get(['id', 'name', 'last_used_at', 'created_at'])
            ->map(fn($c) => [
                'id'           => $c->id,
                'name'         => $c->name,
                'last_used_at' => $c->last_used_at?->diffForHumans() ?? 'Mai usato',
                'created_at'   => $c->created_at->format('d/m/Y'),
            ]);

        return response()->json($list);
    }

    /** DELETE /webauthn/credentials/{id} — rimuovi dispositivo */
    public function deleteCredential(Request $request, int $id): JsonResponse
    {
        $credential = WebAuthnCredential::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $credential->delete();

        return response()->json(['success' => true]);
    }

    // ── SWITCH PROFILO (multi-account sullo stesso dispositivo) ───────────────

    /**
     * POST /webauthn/switch/options
     * Restituisce una challenge discoverable per il cambio profilo in-sessione.
     * Il browser mostrerà TUTTE le passkey registrate per il dominio.
     * Richiede utente autenticato.
     */
    public function switchOptions(Request $request): JsonResponse
    {
        $serializer = $this->makeSerializer();
        try {
            $options = PublicKeyCredentialRequestOptions::create(
                challenge:        random_bytes(32),
                timeout:          60000,
                rpId:             $this->rpId(),
                allowCredentials: [],   // vuoto = discoverable: browser mostra tutti i profili
                userVerification: 'required',
            );

            $json = $serializer->serialize($options, 'json');
            session(['webauthn_switch_options' => $json]);

            return response()->json(json_decode($json, true));
        } catch (Throwable $e) {
            return response()->json(['error' => 'Errore: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /webauthn/switch/verify
     * Verifica l'assertion e switcha la sessione al profilo selezionato.
     * Se userHandle corrisponde all'utente già autenticato, risponde con same_user=true.
     */
    public function switchVerify(Request $request): JsonResponse
    {
        $serializer  = $this->makeSerializer();
        $optionsJson = session('webauthn_switch_options');
        session()->forget('webauthn_switch_options');

        if (! $optionsJson) {
            return response()->json(['error' => 'Sessione scaduta. Riprova.'], 422);
        }

        try {
            $options = $serializer->deserialize(
                $optionsJson,
                PublicKeyCredentialRequestOptions::class,
                'json',
            );

            $credential = $serializer->deserialize(
                json_encode($request->all()),
                PublicKeyCredential::class,
                'json',
            );

            // Leggi lo user_id dal userHandle (binario → intero)
            $userHandle = $credential->response->userHandle ?? null;
            if (! $userHandle) {
                return response()->json(['error' => 'Impossibile identificare il profilo.'], 401);
            }
            $targetUserId = (int) $userHandle;

            // Trova la credential nel DB
            $storedCred = WebAuthnCredential::where('credential_id', $this->b64UrlEncode($credential->rawId))
                ->where('user_id', $targetUserId)
                ->first();

            if (! $storedCred) {
                return response()->json(['error' => 'Dispositivo non riconosciuto per questo profilo.'], 401);
            }

            $source = $serializer->deserialize(
                $storedCred->credential_source,
                PublicKeyCredentialSource::class,
                'json',
            );

            $validator     = AuthenticatorAssertionResponseValidator::create();
            $updatedSource = $validator->check(
                $source,
                $credential->response,
                $options,
                $this->rpId(),
                (string) $targetUserId,
                $this->securedRpIds(),
            );

            // Aggiorna contatore e data
            $storedCred->update([
                'credential_source' => $serializer->serialize($updatedSource, 'json'),
                'last_used_at'      => now(),
            ]);

            $targetUser = User::findOrFail($targetUserId);

            // Stessa persona già loggata — nessun switch
            if ($targetUserId === Auth::id()) {
                return response()->json([
                    'same_user' => true,
                    'message'   => 'Sei già su questo profilo.',
                ]);
            }

            // Switch sessione
            Auth::login($targetUser, false);
            $request->session()->regenerate();

            $redirect = $targetUser->canAccessBackoffice()
                ? route('admin.dashboard')
                : route('portal.dashboard');

            return response()->json([
                'redirect'     => $redirect,
                'profile_name' => $targetUser->name,
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Switch fallito: ' . $e->getMessage(),
            ], 401);
        }
    }

}