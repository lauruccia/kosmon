<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Logica condivisa di validazione/salvataggio dei metodi di pagamento EUR di
 * un'azienda (payment_gateways), usata sia dal self-service azienda
 * (PaymentGatewayController) sia dall'admin per conto dell'azienda
 * (Admin\CompanyPaymentGatewayController) — stesso pattern di
 * AuthorizesBackoffice per gli altri controller Admin/*.
 */
trait ManagesPaymentGateways
{
    /**
     * Valida l'input del form per il provider indicato secondo
     * PaymentGateway::CREDENTIAL_FIELDS, e salva (crea o aggiorna) il
     * PaymentGateway dell'azienda. I campi "sensitive" (secret_key,
     * client_secret) lasciati vuoti in fase di modifica NON sovrascrivono
     * il valore già salvato — evita di dover re-inserire la chiave segreta
     * ogni volta che si cambia un altro campo.
     */
    protected function saveGatewayFromRequest(Request $request, Company $company, string $provider, User $actor): PaymentGateway
    {
        if (! array_key_exists($provider, PaymentGateway::CREDENTIAL_FIELDS)) {
            abort(404);
        }

        $fields = PaymentGateway::CREDENTIAL_FIELDS[$provider];

        $rules = [
            'label' => ['nullable', 'string', 'max:100'],
        ];
        foreach ($fields as $field) {
            $isSensitiveEdit = $field['sensitive'] ?? false;
            $rules['credentials.' . $field['key']] = $isSensitiveEdit
                ? ['nullable', 'string', 'max:255']
                : ['required', 'string', 'max:255'];
        }

        $validated = $request->validate($rules);

        $gateway = PaymentGateway::query()
            ->where('company_id', $company->id)
            ->where('provider', $provider)
            ->first();

        $existingCredentials = $gateway?->credentials ?? [];
        $incomingCredentials = $validated['credentials'] ?? [];

        $credentials = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $incoming = trim((string) ($incomingCredentials[$key] ?? ''));

            if ($incoming === '' && ($field['sensitive'] ?? false)) {
                // Campo sensibile lasciato vuoto: mantieni il valore esistente.
                $credentials[$key] = $existingCredentials[$key] ?? null;
            } else {
                $credentials[$key] = $incoming;
            }
        }

        // I campi sensibili sono obbligatori alla PRIMA configurazione (non
        // possiamo lasciarli vuoti se non c'era nulla prima).
        if (! $gateway) {
            foreach ($fields as $field) {
                if (($field['sensitive'] ?? false) && empty($credentials[$field['key']])) {
                    throw ValidationException::withMessages([
                        'credentials.' . $field['key'] => 'Questo campo è obbligatorio.',
                    ]);
                }
            }
        }

        if ($gateway) {
            $gateway->update([
                'label'               => $validated['label'] ?? $gateway->label,
                'credentials'         => $credentials,
                'updated_by_user_id'  => $actor->id,
            ]);
        } else {
            $gateway = PaymentGateway::create([
                'company_id'         => $company->id,
                'provider'           => $provider,
                'label'              => $validated['label'] ?? null,
                'is_active'          => true,
                'credentials'        => $credentials,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
        }

        return $gateway->refresh();
    }

    protected function findCompanyGatewayOrFail(Company $company, string $provider): PaymentGateway
    {
        return PaymentGateway::query()
            ->where('company_id', $company->id)
            ->where('provider', $provider)
            ->firstOrFail();
    }
}
