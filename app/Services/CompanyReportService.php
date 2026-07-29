<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CompanyReport;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\CompanyReportContractSignedNotification;
use App\Notifications\CompanyReportRejectedNotification;
use App\Notifications\CompanyReportSubmittedNotification;
use App\Notifications\Concerns\NotifiesAdmins;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Feature "segnalazione azienda" (richiesta di Laura, 29/07/2026): un
 * cliente qualsiasi puo' segnalare in testo libero un'azienda dove
 * vorrebbe spendere il proprio saldo KY. La segnalazione arriva
 * all'agente di riferimento del cliente (o alla radice di sistema se il
 * cliente non ne ha uno, vedi resolveAgentFor()) e in copia/visibilita' a
 * tutti gli admin. Se l'agente riesce a firmare un contratto con
 * l'azienda segnalata, il sistema eroga SUBITO (nessuna approvazione
 * admin) un bonus KY al segnalante, a carico del conto madre — stesso
 * importo del livello "attivita'" del referral (SystemSetting::
 * userLimitDefaults()->referral_bonus_attivita_amount), ma erogato con
 * una propria idempotency key indipendente da ReferralBonusService
 * (flusso completamente separato).
 */
class CompanyReportService
{
    /**
     * Agente a cui instradare le segnalazioni di $client: il suo agente di
     * riferimento MLM se presente, altrimenti la radice di sistema (per i
     * "clienti diretti KNM" senza mlm_client_agent_id).
     */
    public function resolveAgentFor(User $client): ?User
    {
        return $client->mlmClientAgent ?: app(MlmTreeService::class)->systemRootAgent();
    }

    /**
     * Crea la segnalazione, risolve e salva lo snapshot dell'agente
     * destinatario, notifica l'agente (se presente) e tutti gli admin in
     * copia. Le notifiche non devono mai bloccare il salvataggio della
     * segnalazione, stesso pattern difensivo di ReferralBonusService.
     */
    public function submit(User $client, array $data): CompanyReport
    {
        $agent = $this->resolveAgentFor($client);

        $report = CompanyReport::create([
            'user_id'        => $client->id,
            'agent_user_id'  => $agent?->id,
            'company_name'   => $data['company_name'],
            'company_city'   => $data['company_city'] ?? null,
            'company_notes'  => $data['company_notes'] ?? null,
            'status'         => CompanyReport::STATUS_PENDING,
        ]);

        AuditLog::create([
            'actor_user_id'  => $client->id,
            'event'          => 'company_report.submitted',
            'auditable_type' => CompanyReport::class,
            'auditable_id'   => $report->id,
            'context'        => [
                'company_name'  => $report->company_name,
                'company_city'  => $report->company_city,
                'agent_user_id' => $agent?->id,
            ],
        ]);

        try {
            $notification = new CompanyReportSubmittedNotification($report);

            if ($agent && $agent->email) {
                $agent->notify($notification);
            }

            NotifiesAdmins::notifyAdminsOfCompanyReport($report);
        } catch (\Throwable $e) {
            Log::warning("Notifica segnalazione azienda fallita per il report {$report->id}: " . $e->getMessage());
        }

        return $report;
    }

    /**
     * L'agente conferma di aver firmato un contratto con l'azienda
     * segnalata: eroga subito il bonus KY al segnalante (a carico del
     * conto madre) e chiude la segnalazione. Idempotente — un secondo
     * click/replay non genera un secondo bonus, ne' fallisce: ritorna
     * semplicemente il report gia' chiuso.
     */
    public function markContractSigned(CompanyReport $report, User $actor, ?string $note = null): CompanyReport
    {
        return DB::transaction(function () use ($report, $actor, $note) {
            $locked = CompanyReport::lockForUpdate()->findOrFail($report->id);

            if (! $locked->isPending()) {
                return $locked; // gia' processato: no-op (double click / replay)
            }

            $amount = (int) (SystemSetting::userLimitDefaults()->referral_bonus_attivita_amount ?? 0);
            $transfer = null;

            if ($amount > 0) {
                $idempotencyKey = "company_report_bonus_{$locked->id}";
                $existing = Transfer::where('idempotency_key', $idempotencyKey)->first();

                if ($existing) {
                    $transfer = $existing;
                } else {
                    $systemAccount = Account::systemAccount();
                    $reporterAccount = Account::where('owner_user_id', $locked->user_id)
                        ->whereNull('parent_account_id')
                        ->where('status', 'active')
                        ->first();

                    if ($systemAccount && $reporterAccount) {
                        $systemUser = ($actor->is_super_admin ?? false)
                            ? $actor
                            : User::where('is_super_admin', true)->where('is_active', true)->first();

                        if ($systemUser) {
                            $transfer = app(TransferBookingService::class)->book([
                                'initiated_by'    => $systemUser->id,
                                'from_account_id' => $systemAccount->id,
                                'to_account_id'   => $reporterAccount->id,
                                'amount'          => $amount,
                                'description'     => "Bonus segnalazione azienda: {$locked->company_name}",
                                'kind'            => 'portal_cashback', // esente da fee, come gli altri bonus
                                'idempotency_key' => $idempotencyKey,
                            ]);
                        } else {
                            Log::warning('CompanyReportService: nessun super admin trovato per erogare il bonus segnalazione azienda.');
                        }
                    }
                }
            }

            $locked->forceFill([
                'status'       => CompanyReport::STATUS_CONTRACT_SIGNED,
                'agent_notes'  => $note,
                'actioned_by'  => $actor->id,
                'actioned_at'  => now(),
                'bonus_transfer_id' => $transfer?->id,
            ])->save();

            AuditLog::create([
                'actor_user_id'  => $actor->id,
                'event'          => 'company_report.contract_signed',
                'auditable_type' => CompanyReport::class,
                'auditable_id'   => $locked->id,
                'context'        => [
                    'amount'      => $amount,
                    'transfer_id' => $transfer?->id,
                ],
            ]);

            $reporter = $locked->reporter;
            if ($reporter) {
                try {
                    $reporter->notify(new CompanyReportContractSignedNotification($locked, $transfer));
                } catch (\Throwable $e) {
                    Log::warning("Notifica contratto firmato fallita per il report {$locked->id}: " . $e->getMessage());
                }
            }

            return $locked;
        });
    }

    /**
     * L'agente segna la segnalazione come non riuscita (es. azienda non
     * interessata): nessun bonus, richiede una breve motivazione.
     */
    public function markRejected(CompanyReport $report, User $actor, string $reason): CompanyReport
    {
        return DB::transaction(function () use ($report, $actor, $reason) {
            $locked = CompanyReport::lockForUpdate()->findOrFail($report->id);

            if (! $locked->isPending()) {
                return $locked; // gia' processato: no-op
            }

            $locked->forceFill([
                'status'      => CompanyReport::STATUS_REJECTED,
                'agent_notes' => $reason,
                'actioned_by' => $actor->id,
                'actioned_at' => now(),
            ])->save();

            AuditLog::create([
                'actor_user_id'  => $actor->id,
                'event'          => 'company_report.rejected',
                'auditable_type' => CompanyReport::class,
                'auditable_id'   => $locked->id,
                'context'        => ['reason' => $reason],
            ]);

            $reporter = $locked->reporter;
            if ($reporter) {
                try {
                    $reporter->notify(new CompanyReportRejectedNotification($locked));
                } catch (\Throwable $e) {
                    Log::warning("Notifica rifiuto segnalazione fallita per il report {$locked->id}: " . $e->getMessage());
                }
            }

            return $locked;
        });
    }
}
