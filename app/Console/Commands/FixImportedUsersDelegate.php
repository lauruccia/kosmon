<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class FixImportedUsersDelegate extends Command
{
    protected $signature   = 'kmoney:fix-imported-delegate';
    protected $description = 'Rimuove managed_account_id dagli utenti importati da kosmomoney (erano owner, non delegati)';

    public function handle(): int
    {
        $affected = User::whereNotNull('company_id')
            ->whereNotNull('managed_account_id')
            ->get(['id', 'email', 'company_id', 'managed_account_id']);

        if ($affected->isEmpty()) {
            $this->info('Nessun utente da correggere.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Email', 'company_id', 'managed_account_id (rimosso)'],
            $affected->map(fn ($u) => [$u->id, $u->email, $u->company_id, $u->managed_account_id])
        );

        if (! $this->confirm("Vuoi azzerare managed_account_id per questi {$affected->count()} utenti?", true)) {
            $this->warn('Operazione annullata.');
            return self::FAILURE;
        }

        User::whereNotNull('company_id')
            ->whereNotNull('managed_account_id')
            ->update(['managed_account_id' => null]);

        $this->info("✓ {$affected->count()} utenti corretti. Ora vedranno la dashboard owner con il saldo reale.");

        return self::SUCCESS;
    }
}
