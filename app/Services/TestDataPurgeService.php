<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Announcement;
use App\Models\AnnouncementReply;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\BalanceAlert;
use App\Models\Company;
use App\Models\ContractSignature;
use App\Models\CreditLimit;
use App\Models\CreditLimitRequest;
use App\Models\KyCardPurchase;
use App\Models\KycDocument;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\LoginLog;
use App\Models\NettingProposal;
use App\Models\NfcCard;
use App\Models\NfcCardAuthSession;
use App\Models\NfcCardLog;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\PushSubscription;
use App\Models\SavedBeneficiary;
use App\Models\SubAccountInvitation;
use App\Models\SubAccountLimitRequest;
use App\Models\Transfer;
use App\Models\User;
use App\Models\WebAuthnCredential;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cancellazione fisica e COMPLETA di dati di prova/test: un'intera Company (con i
 * suoi Account, User e movimenti) oppure un singolo Account privato (KYP) con il suo
 * utente proprietario.
 *
 * Differenza rispetto a AdminController::deleteTransferWithCascade() (che parte da UN
 * Transfer): qui si parte da uno o più Account e si elimina l'INTERO grafo collegato:
 * ogni movimento che li tocca — comprese le controparti reali, i cui saldi vengono
 * ripristinati esattamente come nel flusso esistente — più tutti i record applicativi
 * (KYC, contratti, credenziali, card NFC, shop, annunci, notifiche...).
 *
 * Il circuito resta a 0: ogni Transfer eliminato è internamente bilanciato (1 debit +
 * 1 credit di pari importo), quindi annullare entrambe le partite non altera la somma
 * dei saldi. Il metodo verifica l'invariante alla fine e ABORTISCE (rollback, la
 * transazione va aperta dal chiamante) se qualcosa non torna.
 *
 * Protezioni:
 *  - Il conto sistema (is_system_account) non è MAI incluso.
 *  - Un super admin non può essere eliminato da qui.
 *  - Se esistono acquisti KY Card (Stripe/PayPal) COMPLETATI o RIMBORSATI sugli
 *    account/utenti coinvolti, l'operazione si blocca di default: sono soldi reali
 *    entrati nel circuito dall'esterno, non "dati di prova" — vanno gestiti a mano
 *    (o l'admin deve passare force=true consapevolmente).
 *
 * DEVE essere chiamato dentro una DB::transaction() dal chiamante (come
 * deleteTransferWithCascade).
 */
class TestDataPurgeService
{
    /**
     * Elimina un'intera azienda di test: tutti i suoi Account, i suoi User, e ogni
     * record collegato.
     *
     * @return array{accounts:int, users:int, transfers:int, company:?string}
     */
    public function purgeCompany(Company $company, User $actor, ?string $ip, bool $force = false): array
    {
        $accountIds = Account::query()
            ->where('company_id', $company->id)
            ->where('is_system_account', false)
            ->pluck('id');

        $userIds = User::query()->where('company_id', $company->id)->pluck('id');

        abort_if(
            $accountIds->isEmpty() && $userIds->isEmpty(),
            422,
            "L'azienda {$company->name} non ha account né utenti collegati: nulla da eliminare qui."
        );

        return $this->purge($accountIds, $userIds, $company, $actor, $ip, $force);
    }

    /**
     * Elimina un singolo conto privato (KYP) di test insieme al suo utente
     * proprietario. Non utilizzabile per conti aziendali (KYB): per quelli va
     * eliminata l'intera Company con purgeCompany().
     *
     * @return array{accounts:int, users:int, transfers:int, company:?string}
     */
    public function purgeAccount(Account $account, User $actor, ?string $ip, bool $force = false): array
    {
        abort_if($account->is_system_account, 422, 'Il conto sistema non può essere eliminato.');
        abort_if(
            $account->company_id !== null,
            422,
            "Questo è un conto aziendale collegato a un'azienda: elimina l'intera azienda dalla sua pagina."
        );

        $accountIds = collect([$account->id]);
        $userIds = $account->owner_user_id !== null ? collect([$account->owner_user_id]) : collect();

        return $this->purge($accountIds, $userIds, null, $actor, $ip, $force);
    }

    /**
     * Riepilogo IN SOLA LETTURA di cosa verrebbe eliminato, per la pagina di conferma.
     * Non modifica nulla.
     *
     * @return array{accounts:int, users:int, transfers:int, balance_total:int, has_real_money:bool}
     */
    public function previewCompany(Company $company): array
    {
        $accountIds = Account::query()->where('company_id', $company->id)->where('is_system_account', false)->pluck('id');
        $userIds = User::query()->where('company_id', $company->id)->pluck('id');

        return $this->preview($accountIds, $userIds);
    }

    /** @return array{accounts:int, users:int, transfers:int, balance_total:int, has_real_money:bool} */
    public function previewAccount(Account $account): array
    {
        $accountIds = collect([$account->id]);
        $userIds = $account->owner_user_id !== null ? collect([$account->owner_user_id]) : collect();

        return $this->preview($accountIds, $userIds);
    }

    /**
     * @param  Collection<int,int>  $accountIds
     * @param  Collection<int,int>  $userIds
     */
    private function preview(Collection $accountIds, Collection $userIds): array
    {
        $transferIds = $this->collectTransferGraph($accountIds);

        $realMoneyExists = KyCardPurchase::query()
            ->whereIn('status', ['completed', 'refunded'])
            ->where(fn ($q) => $q->whereIn('account_id', $accountIds)->orWhereIn('user_id', $userIds))
            ->exists();

        return [
            'accounts'       => $accountIds->count(),
            'users'          => $userIds->count(),
            'transfers'      => $transferIds->count(),
            'balance_total'  => (int) Account::whereIn('id', $accountIds)->sum('available_balance'),
            'has_real_money' => $realMoneyExists,
        ];
    }

    /**
     * @param  Collection<int,int>  $accountIds
     * @param  Collection<int,int>  $userIds
     */
    private function purge(Collection $accountIds, Collection $userIds, ?Company $company, User $actor, ?string $ip, bool $force): array
    {
        abort_if($accountIds->isEmpty() && $userIds->isEmpty(), 422, 'Nessun account o utente da eliminare.');

        abort_if(
            Account::whereIn('id', $accountIds)->where('is_system_account', true)->exists(),
            422,
            'Il conto sistema non può essere incluso nella cancellazione.'
        );

        if ($userIds->isNotEmpty()) {
            abort_if(
                User::whereIn('id', $userIds)->where('is_super_admin', true)->exists(),
                422,
                'Non è possibile eliminare un account super admin con questa funzione.'
            );
        }

        // ── Guardia soldi reali: acquisti KY Card completati/rimborsati (Stripe/PayPal) ──
        $realMoneyExists = KyCardPurchase::query()
            ->whereIn('status', ['completed', 'refunded'])
            ->where(fn ($q) => $q->whereIn('account_id', $accountIds)->orWhereIn('user_id', $userIds))
            ->exists();
        abort_if(
            $realMoneyExists && ! $force,
            422,
            'Trovati acquisti KY Card completati o rimborsati (soldi reali via Stripe/PayPal) su questi account: '
                . 'la cancellazione è bloccata per sicurezza. Non sono dati di prova. Gestisci manualmente prima di riprovare.'
        );

        // ── 1. Raccogli l'intero grafo di Transfer collegati (bidirezionale) ──
        $transferIds = $this->collectTransferGraph($accountIds);

        // ── 2. Netting proposals: FK non nullable su accounts/transfers/users, vanno eliminate prima ──
        $nettingIds = NettingProposal::query()
            ->where(fn ($q) => $q
                ->whereIn('proposer_account_id', $accountIds)
                ->orWhereIn('counterparty_account_id', $accountIds)
                ->orWhereIn('net_payer_account_id', $accountIds)
                ->orWhereIn('net_transfer_id', $transferIds)
                ->orWhereIn('actioned_by', $userIds)
                ->orWhereIn('proposed_by', $userIds))
            ->pluck('id');
        $nettingCount = $nettingIds->count();
        NettingProposal::whereIn('id', $nettingIds)->delete();

        // ── 3. Payment plan: FK non nullable su accounts, vanno eliminati prima (cascade sulle rate) ──
        $planIds = PaymentPlan::query()
            ->where(fn ($q) => $q->whereIn('from_account_id', $accountIds)->orWhereIn('to_account_id', $accountIds))
            ->pluck('id');
        $planCount = $planIds->count();
        PaymentPlanInstallment::whereIn('payment_plan_id', $planIds)->delete();
        PaymentPlan::whereIn('id', $planIds)->delete();

        // ── 4. Ripristina i saldi (anche delle controparti reali) ed elimina ledger + transfer ──
        $this->restoreBalancesAndDeleteTransfers($transferIds);

        // ── 5. KY Card purchases residui: solo pending/failed possono restare qui (soldi reali già esclusi sopra) ──
        KyCardPurchase::query()
            ->where(fn ($q) => $q->whereIn('account_id', $accountIds)->orWhereIn('user_id', $userIds))
            ->delete();

        // ── 6. Ogni altro record applicativo collegato ad account/utenti/azienda ──
        $depCounts = $this->purgeDependentRecords($accountIds, $userIds, $company);

        // ── 7. Elimina account, poi utenti, poi (se presente) l'azienda ──
        $accountSnapshot = Account::whereIn('id', $accountIds)->get(['id', 'uuid', 'account_name', 'available_balance'])->toArray();
        Account::whereIn('id', $accountIds)->delete();

        $userSnapshot = User::whereIn('id', $userIds)->get(['id', 'name', 'email'])->toArray();
        User::whereIn('id', $userIds)->delete();

        if ($company !== null) {
            $company->delete();
        }

        // ── 8. Verifica invariante di circuito chiuso: somma saldi = 0 ──
        $totalBalance = (int) Account::query()->sum('available_balance');
        abort_if(
            abs($totalBalance) > 1,
            500,
            "Anomalia rilevata dopo la cancellazione: la somma dei saldi del circuito non è più zero ({$totalBalance} centesimi). Operazione annullata."
        );

        // ── 9. Unica traccia che resta: l'audit log dell'operazione di purge stessa ──
        AuditLog::create([
            'actor_user_id'  => $actor->id,
            'event'          => 'admin.test_data.purged',
            'auditable_type' => $company !== null ? Company::class : Account::class,
            'auditable_id'   => $company?->id ?? ($accountSnapshot[0]['id'] ?? null),
            'ip_address'     => $ip,
            'context'        => [
                'reason'                 => 'cancellazione completa dati di test',
                'company_name'           => $company?->name,
                'accounts_deleted'       => $accountSnapshot,
                'users_deleted'          => $userSnapshot,
                'transfers_deleted'      => $transferIds->count(),
                'netting_deleted'        => $nettingCount,
                'payment_plans_deleted'  => $planCount,
                'dependent_records'      => $depCounts,
                'forced'                 => $force,
            ],
        ]);

        return [
            'accounts'  => count($accountSnapshot),
            'users'     => count($userSnapshot),
            'transfers' => $transferIds->count(),
            'company'   => $company?->name,
        ];
    }

    /**
     * Espande a partire dagli account il set completo di Transfer da eliminare:
     * tutti quelli in cui l'account compare come from/to, più i loro collegati
     * (commissioni via related_transfer_id, cashback via idempotency_key, storni via
     * reversed_transfer_id) — in entrambe le direzioni, iterando fino a un punto
     * fisso, così un intero "cluster" finanziario (pagamento + fee + cashback +
     * eventuale storno) viene sempre eliminato per intero anche se solo un lato
     * tocca un account di test.
     *
     * @param  Collection<int,int>  $accountIds
     * @return Collection<int,int>
     */
    private function collectTransferGraph(Collection $accountIds): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        $ids = Transfer::query()
            ->where(fn ($q) => $q->whereIn('from_account_id', $accountIds)->orWhereIn('to_account_id', $accountIds))
            ->pluck('id');

        for ($i = 0; $i < 25; $i++) {
            $before = $ids->count();

            $cashbackKeys = Transfer::whereIn('id', $ids)->pluck('uuid')->map(fn ($uuid) => 'cashback_' . $uuid);
            $parentIds = Transfer::whereIn('id', $ids)
                ->get(['related_transfer_id', 'reversed_transfer_id'])
                ->flatMap(fn ($t) => [$t->related_transfer_id, $t->reversed_transfer_id])
                ->filter()
                ->unique();

            $more = Transfer::query()
                ->where(fn ($q) => $q
                    ->whereIn('related_transfer_id', $ids)
                    ->orWhereIn('reversed_transfer_id', $ids)
                    ->orWhereIn('idempotency_key', $cashbackKeys)
                    ->orWhereIn('id', $parentIds))
                ->pluck('id');

            $ids = $ids->merge($more)->unique()->values();

            if ($ids->count() === $before) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Ripristina i saldi di TUTTI i conti coinvolti (comprese le controparti reali:
     * è così che il circuito resta a 0 e le loro statistiche tornano corrette come se
     * il movimento di test non fosse mai avvenuto), poi elimina ledger entry e
     * transfer. Prima slega i self-reference (related/reversed) così l'ordine di
     * cancellazione non è più un vincolo.
     *
     * @param  Collection<int,int>  $transferIds
     */
    private function restoreBalancesAndDeleteTransfers(Collection $transferIds): void
    {
        if ($transferIds->isEmpty()) {
            return;
        }

        Transfer::whereIn('id', $transferIds)->update([
            'related_transfer_id'  => null,
            'reversed_transfer_id' => null,
        ]);

        $entries = LedgerEntry::whereIn('transfer_id', $transferIds)->get(['account_id', 'direction', 'amount']);

        foreach ($entries->groupBy('account_id') as $accountId => $accountEntries) {
            $account = Account::query()->lockForUpdate()->find($accountId);
            if ($account === null) {
                continue; // conto già eliminato in un passaggio precedente (es. entrambi i lati sono di test)
            }

            $delta = $accountEntries->sum(fn ($entry) => $entry->direction === 'credit' ? -$entry->amount : $entry->amount);

            $account->forceFill([
                'available_balance' => (int) $account->available_balance + (int) $delta,
            ])->save();
        }

        LedgerEntry::whereIn('transfer_id', $transferIds)->delete();
        Transfer::whereIn('id', $transferIds)->delete();
    }

    /**
     * Elimina ogni altro record applicativo collegato ad account/utenti/azienda:
     * KYC, contratti, credenziali, card NFC, shop, annunci, webhook, notifiche...
     * Copre sia le FK con onDelete automatico (per certezza indipendentemente
     * dall'enforcement FK del DB) sia quelle restrict che altrimenti bloccherebbero
     * la cancellazione di account/utenti/azienda più avanti.
     *
     * @param  Collection<int,int>  $accountIds
     * @param  Collection<int,int>  $userIds
     * @return array<string,int>
     */
    private function purgeDependentRecords(Collection $accountIds, Collection $userIds, ?Company $company): array
    {
        $companyIds = $company !== null ? collect([$company->id]) : collect();
        $counts = [];

        // — Legati agli account —
        $counts['credit_limits'] = CreditLimit::whereIn('account_id', $accountIds)->delete();
        $counts['credit_limit_requests'] = CreditLimitRequest::whereIn('account_id', $accountIds)->delete();
        $counts['account_managers'] = DB::table('account_managers')
            ->where(fn ($q) => $q->whereIn('account_id', $accountIds)->orWhereIn('user_id', $userIds))
            ->delete();
        $counts['sub_account_invitations'] = SubAccountInvitation::query()
            ->where(fn ($q) => $q->whereIn('account_id', $accountIds)->orWhereIn('invited_by', $userIds))
            ->delete();
        $counts['balance_alerts'] = BalanceAlert::whereIn('account_id', $accountIds)->delete();
        // softDeletes: forceDelete() per una rimozione fisica reale, non un semplice deleted_at.
        $counts['sub_account_limit_requests'] = SubAccountLimitRequest::query()
            ->where(fn ($q) => $q->whereIn('sub_account_id', $accountIds)->orWhereIn('requested_by_user_id', $userIds))
            ->forceDelete();
        $counts['saved_beneficiaries'] = SavedBeneficiary::query()
            ->where(fn ($q) => $q->whereIn('owner_account_id', $accountIds)->orWhereIn('beneficiary_account_id', $accountIds))
            ->delete();

        // — Card NFC (aziendali o private) —
        $nfcCardIds = NfcCard::query()
            ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereIn('owner_user_id', $userIds)->orWhereIn('issued_by', $userIds))
            ->pluck('id');
        $counts['nfc_card_logs'] = NfcCardLog::query()
            ->where(fn ($q) => $q->whereIn('nfc_card_id', $nfcCardIds)->orWhereIn('merchant_company_id', $companyIds))
            ->delete();
        $counts['nfc_card_auth_sessions'] = NfcCardAuthSession::query()
            ->where(fn ($q) => $q->whereIn('nfc_card_id', $nfcCardIds)->orWhereIn('merchant_company_id', $companyIds))
            ->delete();
        $counts['nfc_cards'] = NfcCard::whereIn('id', $nfcCardIds)->delete();

        // — Legati agli utenti —
        $counts['webauthn_credentials'] = WebAuthnCredential::whereIn('user_id', $userIds)->delete();
        $counts['login_logs'] = LoginLog::whereIn('user_id', $userIds)->delete();
        $counts['push_subscriptions'] = PushSubscription::whereIn('user_id', $userIds)->delete();
        $counts['contract_signatures'] = ContractSignature::query()
            ->where(fn ($q) => $q->whereIn('user_id', $userIds)->orWhereIn('company_id', $companyIds))
            ->delete();
        $counts['notifications'] = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $userIds)
            ->delete();

        // — Legati ad azienda e/o utenti (FK restrict su entrambi i lati) —
        $counts['api_tokens'] = ApiToken::query()
            ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereIn('created_by', $userIds))
            ->delete();
        $counts['kyc_documents'] = KycDocument::query()
            ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereIn('uploaded_by_user_id', $userIds))
            ->delete();
        $webhookIds = Webhook::whereIn('company_id', $companyIds)->pluck('id');
        $counts['webhook_deliveries'] = WebhookDelivery::whereIn('webhook_id', $webhookIds)->delete();
        $counts['webhooks'] = Webhook::whereIn('id', $webhookIds)->delete();

        $announcementIds = Announcement::query()
            ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereIn('created_by_user_id', $userIds))
            ->pluck('id');
        $counts['announcement_replies'] = AnnouncementReply::query()
            ->where(fn ($q) => $q
                ->whereIn('announcement_id', $announcementIds)
                ->orWhereIn('user_id', $userIds)
                ->orWhereIn('company_id', $companyIds))
            ->delete();
        $counts['announcements'] = Announcement::whereIn('id', $announcementIds)->delete();

        $counts['listings'] = Listing::query()
            ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereIn('created_by_user_id', $userIds))
            ->delete();

        return array_filter($counts);
    }
}
