<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indici aggiuntivi per performance su MySQL in produzione.
 * Su SQLite vengono creati senza problemi (ignorati se già presenti).
 */
return new class extends Migration
{
    public function up(): void
    {
        // transfers: query più comuni in TransferBookingService e dashboard
        Schema::table('transfers', function (Blueprint $table) {
            if (! $this->indexExists('transfers', 'transfers_from_booked_at_index')) {
                $table->index(['from_account_id', 'status', 'booked_at'], 'transfers_from_booked_at_index');
            }
            if (! $this->indexExists('transfers', 'transfers_to_booked_at_index')) {
                $table->index(['to_account_id', 'status', 'booked_at'], 'transfers_to_booked_at_index');
            }
            if (! $this->indexExists('transfers', 'transfers_initiated_by_booked_index')) {
                $table->index(['initiated_by', 'status', 'booked_at'], 'transfers_initiated_by_booked_index');
            }
        });

        // ledger_entries: usato per storico e saldo
        Schema::table('ledger_entries', function (Blueprint $table) {
            if (! $this->indexExists('ledger_entries', 'ledger_account_direction_index')) {
                $table->index(['account_id', 'direction', 'posted_at'], 'ledger_account_direction_index');
            }
        });

        // accounts: lookup frequente per company e owner
        Schema::table('accounts', function (Blueprint $table) {
            if (! $this->indexExists('accounts', 'accounts_company_status_index')) {
                $table->index(['company_id', 'status'], 'accounts_company_status_index');
            }
            if (! $this->indexExists('accounts', 'accounts_owner_user_status_index')) {
                $table->index(['owner_user_id', 'status'], 'accounts_owner_user_status_index');
            }
        });

        // companies: lookup per status e kyc_status
        Schema::table('companies', function (Blueprint $table) {
            if (! $this->indexExists('companies', 'companies_status_kyc_index')) {
                $table->index(['status', 'kyc_status'], 'companies_status_kyc_index');
            }
            if (! $this->indexExists('companies', 'companies_sector_index')) {
                $table->index('sector', 'companies_sector_index');
            }
        });

        // audit_logs: ricerca per attore e evento
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! $this->indexExists('audit_logs', 'audit_logs_actor_event_index')) {
                $table->index(['actor_user_id', 'event'], 'audit_logs_actor_event_index');
            }
            if (! $this->indexExists('audit_logs', 'audit_logs_created_at_index')) {
                $table->index('created_at', 'audit_logs_created_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndexIfExists('transfers_from_booked_at_index');
            $table->dropIndexIfExists('transfers_to_booked_at_index');
            $table->dropIndexIfExists('transfers_initiated_by_booked_index');
        });
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndexIfExists('ledger_account_direction_index');
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndexIfExists('accounts_company_status_index');
            $table->dropIndexIfExists('accounts_owner_user_status_index');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndexIfExists('companies_status_kyc_index');
            $table->dropIndexIfExists('companies_sector_index');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndexIfExists('audit_logs_actor_event_index');
            $table->dropIndexIfExists('audit_logs_created_at_index');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = \Illuminate\Support\Facades\DB::getDriverName();

        if ($driver === 'sqlite') {
            $result = \Illuminate\Support\Facades\DB::select(
                "SELECT name FROM sqlite_master WHERE type='index' AND name = ?",
                [$index]
            );
            return count($result) > 0;
        }

        try {
            $indexes = \Illuminate\Support\Facades\DB::select(
                "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
                [$index]
            );
            return count($indexes) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
