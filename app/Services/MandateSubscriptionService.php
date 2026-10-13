<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MandateSubscription;
use App\Models\PaymentMandate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Gli abbonamenti dei portali del circuito, visti dall'utente.
 *
 * Questo servizio NON muove denaro e NON decide il calendario: il portale è
 * il padrone dell'abbonamento e manda qui una copia a ogni cambiamento
 * (`sync`). La sola cosa che è dell'utente è il blocco, e le regole sono due:
 *
 *  1. bloccare è un clic, sbloccare chiede lo step-up (la route lo impone):
 *     spegnere dev'essere sempre più facile che accendere;
 *  2. il portale non può mai sbloccare: `sync` non tocca `blocked_at`, né per
 *     un abbonamento nuovo né per uno già esistente. Se il portale ricrea
 *     l'abbonamento con un altro identificativo, l'utente lo vede comparire
 *     nell'elenco e lo può bloccare a sua volta.
 *
 * Il blocco vale all'addebito: `PaymentMandateService::charge()` rifiuta con
 * 403 `subscription_blocked` ogni addebito che dichiara di appartenere a un
 * abbonamento bloccato.
 */
class MandateSubscriptionService
{
    /**
     * Crea o aggiorna la copia di un abbonamento del portale.
     *
     * @param array{title: string, items?: ?array<int, string>, seller_account_number?: ?string, amount?: ?int, interval_months?: ?int, status: string, next_charge_at?: ?string, last_error?: ?string} $data
     *
     * @throws RuntimeException se quell'identificativo appartiene già a un altro utente
     */
    public function sync(User $user, string $clientId, string $externalId, array $data): MandateSubscription
    {
        $existing = MandateSubscription::query()
            ->where('client_id', $clientId)
            ->where('external_id', $externalId)
            ->first();

        // Un identificativo del portale appartiene a un solo utente. Se arriva
        // con il token di un altro, o il portale ha un bug o qualcuno sta
        // provando a scrivere sull'abbonamento di un estraneo: in entrambi i
        // casi non si tocca niente.
        if ($existing !== null && $existing->user_id !== $user->id) {
            throw new RuntimeException('Questo abbonamento appartiene a un altro utente.');
        }

        $status = (string) $data['status'];

        $fields = [
            'title'                 => (string) $data['title'],
            'items'                 => array_values(array_filter((array) ($data['items'] ?? []), 'is_string')),
            'seller_account_number' => $data['seller_account_number'] ?? null,
            'amount'                => $data['amount'] ?? null,
            'interval_months'       => (int) ($data['interval_months'] ?? 1),
            'status'                => $status,
            'next_charge_at'        => $this->date($data['next_charge_at'] ?? null),
            'last_error'            => $data['last_error'] ?? null,
        ];

        // Un abbonamento chiuso non ha un prossimo addebito, e la data di
        // chiusura si scrive una volta sola: la prima.
        if ($status === MandateSubscription::CANCELLED) {
            $fields['next_charge_at'] = null;
        }

        if ($existing === null) {
            $subscription = MandateSubscription::create($fields + [
                'user_id'      => $user->id,
                'client_id'    => $clientId,
                'external_id'  => $externalId,
                'cancelled_at' => $status === MandateSubscription::CANCELLED ? now() : null,
            ]);

            $this->audit('subscription.registered', $user->id, $subscription, null, [
                'client_id'   => $clientId,
                'external_id' => $externalId,
                'title'       => $subscription->title,
                'amount'      => $subscription->amount,
            ]);

            return $subscription;
        }

        $statusBefore = $existing->status;

        if ($status === MandateSubscription::CANCELLED) {
            $fields['cancelled_at'] = $existing->cancelled_at ?? now();
        } else {
            // Il portale lo ha riattivato (non succede oggi su Lamèss, ma la
            // copia deve dire la verità, non ricordare una chiusura vecchia).
            $fields['cancelled_at'] = null;
        }

        $existing->fill($fields)->save();

        if ($statusBefore !== $status) {
            $this->audit('subscription.status_changed', $user->id, $existing, null, [
                'client_id'   => $clientId,
                'external_id' => $externalId,
                'da'          => $statusBefore,
                'a'           => $status,
            ]);
        }

        return $existing;
    }

    /**
     * Blocca: da adesso ogni addebito di questo abbonamento viene rifiutato.
     * Non chiede lo step-up (vedi la route): è l'azione che deve restare facile.
     */
    public function block(MandateSubscription $subscription, ?string $ip = null): void
    {
        if ($subscription->isBlocked()) {
            return;
        }

        $subscription->forceFill(['blocked_at' => now()])->save();

        $this->audit('subscription.blocked', $subscription->user_id, $subscription, $ip, [
            'client_id'   => $subscription->client_id,
            'external_id' => $subscription->external_id,
        ]);
    }

    /**
     * Sblocca: l'utente ridà il permesso. La route sta dietro lo step-up.
     */
    public function unblock(MandateSubscription $subscription, ?string $ip = null): void
    {
        if (! $subscription->isBlocked()) {
            return;
        }

        $subscription->forceFill(['blocked_at' => null])->save();

        $this->audit('subscription.unblocked', $subscription->user_id, $subscription, $ip, [
            'client_id'   => $subscription->client_id,
            'external_id' => $subscription->external_id,
        ]);
    }

    /**
     * L'abbonamento a cui un addebito dichiara di appartenere, se KMoney lo
     * conosce. Cercato per utente E applicazione del mandato: un portale non
     * può dichiarare di appartenere a un abbonamento di un altro utente o di
     * un'altra applicazione per farsi respingere (o far respingere altri).
     */
    public function findForMandate(PaymentMandate $mandate, ?string $externalId): ?MandateSubscription
    {
        if ($externalId === null || $externalId === '') {
            return null;
        }

        return MandateSubscription::query()
            ->where('client_id', $mandate->client_id)
            ->where('user_id', $mandate->user_id)
            ->where('external_id', $externalId)
            ->first();
    }

    // =========================================================================

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value);
    }

    private function audit(string $event, ?int $userId, object $subject, ?string $ip, array $context = []): void
    {
        AuditLog::create([
            'actor_user_id'  => $userId,
            'event'          => $event,
            'auditable_type' => $subject::class,
            'auditable_id'   => $subject->getKey(),
            'ip_address'     => $ip,
            'context'        => Arr::where($context, fn ($v) => $v !== null),
        ]);
    }
}
