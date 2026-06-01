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
                    displayName: $user->name,
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

            WebAuthnCredential::create([
                'user_id'           => $user->id,
                'credential_id'     => $credentialId,
                'credential_source' => $serializer->serialize($source, 'json'),
                'name'              => $request->input('name', 'Dispositivo ' . now()->format('d/m/Y')),
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
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)
            ->where('is_active', true)
            ->first();

        try {
            if (! $user || $user->webAuthnCredentials()->doesntExist()) {
                return response()->json([
                    'error' => 'Nessuna impronta registrata per questo account.',
                ], 404);
            }
        } catch (Throwable $e) {
            return response()->json(['error' => 'Errore del server: ' . $e->getMessage()], 500);
        }

        $serializer = $this->makeSerializer();

        try {
            $allowCredentials = $user->webAuthnCredentials()
                ->get()
                ->map(fn($c) => PublicKeyCredentialDescriptor::create(
                    'public-key',
                    $this->b64UrlDecode($c->credential_id),
                ))
                ->all();

            $options = PublicKeyCredentialRequestOptions::create(
                challenge:        random_bytes(32),
                timeout:          60000,
                rpId:             $this->rpId(),
                allowCredentials: $allowCredentials,
                userVerification: 'required',
            );

            $json = $serializer->serialize($options, 'json');
            session([
                'webauthn_login_options' => $json,
                'webauthn_login_user_id' => $user->id,
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

        if (! $optionsJson || ! $userId) {
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

            // Trova la credential nel DB tramite id (base64url)
            // Nota: $credential->id è deprecated in v4.9; usiamo rawId codificato
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

            $redirect = $user->canAccessBackoffice()
                ? route('admin.dashboard')
                : route('portal.dashboard');

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
}
