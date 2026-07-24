<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlanPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applica il cambio piano quando il pagamento della differenza (Stripe,
 * PayPal, bonifico o KY interno) risulta confermato. Centralizzato qui cosi'
 * sia PlanSubscriptionController (checkout, success, capture PayPal) sia il
 * webhook Stripe condiviso (vedi KyCardController::stripeWebhook) possono
 * richiamare la stessa logica idempotente.
 */
class PlanUpgradeService
{
    public function completePayment(PlanPayment $payment): void
    {
        // Idempotenza: un webhook e una pagina di successo possono arrivare
        // entrambi per lo stesso pagamento.
        if ($payment->isCompleted()) {
            return;
        }

        DB::transaction(function () use ($payment): void {
            $payment = PlanPayment::whereKey($payment->id)->lockForUpdate()->first();
            if ($payment === null || $payment->isCompleted()) {
                return;
            }

            $payment->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            $company = Company::find($payment->company_id);
            if ($company !== null) {
                $company->update(['plan_id' => $payment->to_plan_id]);
            }

            AuditLog::create([
                'actor_user_id'  => $payment->confirmed_by ?? $payment->user_id,
                'event'          => 'portal.plan.upgraded',
                'auditable_type' => Company::class,
                'auditable_id'   => $payment->company_id,
                'context'        => [
                    'plan_payment_uuid' => $payment->uuid,
                    'from_plan_id'      => $payment->from_plan_id,
                    'to_plan_id'        => $payment->to_plan_id,
                    'amount_cents'      => $payment->amount_cents,
                    'payment_method'    => $payment->payment_method,
                ],
            ]);
        });

        try {
            $payment->refresh();
            $payment->user?->notify(new \App\Notifications\PlanUpgradeCompleted($payment));
        } catch (\Exception $e) {
            Log::warning('PlanUpgrade notify failed', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
        }
    }

    public function markFailed(PlanPayment $payment, ?string $reason = null): void
    {
        if ($payment->isCompleted()) {
            return;
        }

        $payment->update([
            'status'      => 'failed',
            'admin_notes' => $reason ?? $payment->admin_notes,
        ]);
    }
}
