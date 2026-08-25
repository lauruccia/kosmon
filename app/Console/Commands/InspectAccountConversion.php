<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Comando diagnostico (SOLA LETTURA, nessuna modifica al DB).
 *
 * Uso: php artisan kmoney:inspect-account KYB00000003J1MHT
 *
 * Serve a fotografare tutto ciò che è collegato a un conto/azienda PRIMA di
 * decidere come convertire un conto business in conto privato: la
 * conversione tocca potenzialmente utenti, sottoconti, prodotti shop,
 * documenti KYC, gateway di pagamento, fido, MLM ecc. — non va fatta alla
 * cieca su un sistema finanziario in produzione.
 */
class InspectAccountConversion extends Command
{
    protected $signature = 'kmoney:inspect-account {numero_conto : Numero conto KY, es. KYB00000003J1MHT}';

    protected $description = 'Mostra tutto ciò che è collegato a un conto/azienda (sola lettura) — usare prima di convertire un conto da azienda a privato';

    public function handle(): int
    {
        $number = strtoupper(trim((string) $this->argument('numero_conto')));

        $account = Account::query()
            ->with(['company', 'ownerUser', 'parentAccount'])
            ->where('uuid', $number)
            ->first();

        if (! $account) {
            $this->error("Nessun conto trovato con numero {$number}.");

            return self::FAILURE;
        }

        $this->info('== CONTO ==');
        $this->table(['Campo', 'Valore'], [
            ['id', $account->id],
            ['uuid (numero conto)', $account->uuid],
            ['account_type (derivato)', $account->account_type],
            ['type', $account->type],
            ['owner_type', $account->owner_type],
            ['status', $account->status],
            ['is_system_account', $account->is_system_account ? 'sì' : 'no'],
            ['parent_account_id (sottoconto di)', $account->parent_account_id ?? '—'],
            ['company_id', $account->company_id ?? '—'],
            ['owner_user_id', $account->owner_user_id ?? '—'],
            ['owner_user (se già valorizzato)', $account->ownerUser?->email ?? '—'],
            ['available_balance (cent)', $account->available_balance],
            ['pending_balance (cent)', $account->pending_balance],
            ['max_balance (cent)', $account->max_balance ?? '—'],
            ['allow_negative_balance', $account->allow_negative_balance ? 'sì' : 'no'],
            ['card_status', $account->card_status],
            ['locked_until', $account->locked_until?->toDateTimeString() ?? '—'],
        ]);

        // Sottoconti agganciati a questo conto
        $children = Account::query()->where('parent_account_id', $account->id)->get(['id', 'uuid', 'account_name', 'status']);
        $this->info('== SOTTOCONTI collegati a questo conto (' . $children->count() . ') ==');
        if ($children->isNotEmpty()) {
            $this->table(['id', 'uuid', 'nome', 'status'], $children->map(fn ($a) => [$a->id, $a->uuid, $a->account_name, $a->status])->all());
        }

        // Manager (utenti con accesso al conto, tipico dei sottoconti/business)
        $managers = $account->managers()->get(['users.id', 'users.name', 'users.email']);
        $pending = $account->pendingManagers()->get(['users.id', 'users.name', 'users.email']);
        $this->info('== MANAGER del conto: attivi ' . $managers->count() . ', in attesa ' . $pending->count() . ' ==');
        if ($managers->isNotEmpty()) {
            $this->table(['id', 'nome', 'email'], $managers->map(fn ($u) => [$u->id, $u->name, $u->email])->all());
        }

        // Utenti che gestiscono direttamente questo conto (managed_account_id)
        $managedUsers = $account->managedUsers()->get(['id', 'name', 'email', 'is_active']);
        $this->info('== UTENTI con managed_account_id = questo conto (' . $managedUsers->count() . ') ==');
        if ($managedUsers->isNotEmpty()) {
            $this->table(['id', 'nome', 'email', 'attivo'], $managedUsers->map(fn ($u) => [$u->id, $u->name, $u->email, $u->is_active ? 'sì' : 'no'])->all());
        }

        // Fido e richieste di fido
        $creditLimits = $account->creditLimits()->get(['id', 'credit_limit', 'status']);
        $creditRequests = $account->creditLimitRequests()->get(['id', 'status']);
        $this->info('== FIDO: ' . $creditLimits->count() . ' record, ' . $creditRequests->count() . ' richieste ==');
        if ($creditLimits->isNotEmpty()) {
            $this->table(['id', 'importo (cent)', 'status'], $creditLimits->map(fn ($c) => [$c->id, $c->credit_limit, $c->status])->all());
        }

        // Movimenti (solo conteggio, non serve la lista completa qui)
        $outgoing = $account->outgoingTransfers()->count();
        $incoming = $account->incomingTransfers()->count();
        $this->info("== MOVIMENTI: {$outgoing} in uscita, {$incoming} in entrata (nessuna modifica prevista qui) ==");

        if (! $account->company_id) {
            $this->warn('Questo conto non è collegato a nessuna Company (company_id vuoto) — probabilmente è già un conto privato o di sistema.');

            return self::SUCCESS;
        }

        $company = $account->company ?? Company::find($account->company_id);

        if (! $company) {
            $this->error("ATTENZIONE: company_id={$account->company_id} ma la Company non esiste più nel DB.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('== AZIENDA (Company) ==');
        $this->table(['Campo', 'Valore'], [
            ['id', $company->id],
            ['nome', $company->name],
            ['slug', $company->slug],
            ['email', $company->email ?? '—'],
            ['partita iva', $company->vat_number ?? '—'],
            ['codice fiscale', $company->fiscal_code ?? '—'],
            ['status', $company->status],
            ['kyc_status', $company->kyc_status],
            ['plan_id', $company->plan_id ?? '—'],
            ['broker_user_id', $company->broker_user_id ?? '—'],
            ['sospesa dal', $company->suspended_at?->toDateTimeString() ?? '—'],
        ]);

        $allAccounts = $company->accounts()->get(['id', 'uuid', 'owner_type', 'is_system_account', 'parent_account_id', 'status']);
        $this->info('== TUTTI I CONTI della company (' . $allAccounts->count() . ') ==');
        $this->table(
            ['id', 'uuid', 'owner_type', 'sistema', 'parent_account_id', 'status'],
            $allAccounts->map(fn ($a) => [$a->id, $a->uuid, $a->owner_type, $a->is_system_account ? 'sì' : 'no', $a->parent_account_id ?? '—', $a->status])->all()
        );

        $users = $company->users()->get(['id', 'name', 'email', 'managed_account_id']);
        $this->info('== UTENTI collegati alla company via company_id (' . $users->count() . ') ==');
        if ($users->isNotEmpty()) {
            $this->table(['id', 'nome', 'email', 'managed_account_id'], $users->map(fn ($u) => [$u->id, $u->name, $u->email, $u->managed_account_id ?? '—'])->all());
        }

        $listingsCount = $company->listings()->count();
        $kycDocsCount = $company->kycDocuments()->count();
        $gatewaysCount = $company->paymentGateways()->count();
        $announcementsCount = $company->announcements()->count();
        $planPaymentsCount = $company->planPayments()->count();

        $this->info('== ALTRI DATI collegati alla company ==');
        $this->table(['Tipo', 'Conteggio'], [
            ['Prodotti shop (listings)', $listingsCount],
            ['Documenti KYC', $kycDocsCount],
            ['Gateway di pagamento (Stripe/PayPal/bonifico)', $gatewaysCount],
            ['Annunci in bacheca', $announcementsCount],
            ['Pagamenti piano/abbonamento', $planPaymentsCount],
        ]);

        $this->newLine();
        $this->comment('Nessuna modifica è stata effettuata. Copia/incolla TUTTO questo output e mandalo a Claude per preparare lo script di conversione corretto per questo caso specifico.');

        return self::SUCCESS;
    }
}
