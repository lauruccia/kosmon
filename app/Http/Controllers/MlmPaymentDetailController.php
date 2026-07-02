<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MlmPaymentDetail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dati bancari (IBAN) dell'agente MLM per la liquidazione EUR. Vedi
 * MLM_PROPOSAL.md §6. Ogni modifica dell'IBAN torna verification_status a
 * 'pending': serve una nuova approvazione admin prima del prossimo payout.
 */
class MlmPaymentDetailController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isMlmAgent(), 403);

        $detail = $user->mlmPaymentDetail;

        return view('portal.mlm.payment-details', [
            'pageTitle' => 'Dati bancari KNM',
            'activeNav' => 'mlm-payment-details',
            'detail'    => $detail,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isMlmAgent(), 403);

        $data = $request->validate([
            'account_holder_name' => ['required', 'string', 'max:150'],
            'iban'                => ['required', 'string', 'max:34', function ($attribute, $value, $fail): void {
                if (! $this->isValidIban($value)) {
                    $fail('IBAN non valido.');
                }
            }],
            'bic_swift'           => ['nullable', 'string', 'max:11'],
            'bank_name'           => ['nullable', 'string', 'max:150'],
        ]);

        $normalizedIban = strtoupper(str_replace(' ', '', $data['iban']));

        $existing = $user->mlmPaymentDetail;
        $ibanChanged = ! $existing || $existing->iban !== $normalizedIban;

        $detail = MlmPaymentDetail::updateOrCreate(
            ['agent_user_id' => $user->id],
            [
                'account_holder_name' => $data['account_holder_name'],
                'iban'                => $normalizedIban,
                'bic_swift'           => $data['bic_swift'] ?? null,
                'bank_name'           => $data['bank_name'] ?? null,
                'verification_status' => $ibanChanged ? 'pending' : ($existing->verification_status ?? 'pending'),
                'verified_by_user_id' => $ibanChanged ? null : ($existing->verified_by_user_id ?? null),
                'verified_at'         => $ibanChanged ? null : ($existing->verified_at ?? null),
            ],
        );

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'mlm.payment_details_updated',
            'auditable_type' => MlmPaymentDetail::class,
            'auditable_id'   => $detail->id,
            'context'        => [
                'iban_last4'    => substr($normalizedIban, -4),
                'iban_changed'  => $ibanChanged,
                'bank_name'     => $detail->bank_name,
            ],
        ]);

        return redirect()
            ->route('portal.mlm.payment-details.edit')
            ->with('portal_success', $ibanChanged
                ? 'Dati bancari salvati. In attesa di verifica da parte dell\'amministrazione prima della prossima liquidazione.'
                : 'Dati bancari aggiornati.');
    }

    /** Validazione formale IBAN (formato + checksum mod-97, ISO 13616). */
    private function isValidIban(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));

        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // Modulo 97 su una stringa numerica potenzialmente lunga: si processa a blocchi.
        $remainder = $numeric;
        while (strlen($remainder) > 2) {
            $chunk = substr($remainder, 0, 9);
            $remainder = (string) ((int) $chunk % 97) . substr($remainder, strlen($chunk));
        }

        return ((int) $remainder % 97) === 1;
    }
}
