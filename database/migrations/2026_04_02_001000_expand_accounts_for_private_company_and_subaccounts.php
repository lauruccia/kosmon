<?php

use App\Support\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL pretende che ogni chiave esterna sia coperta da un indice che
        // COMINCI con la sua colonna. `accounts.company_id` ha una FK verso
        // companies (2026_04_02_000200) e l'unico indice che la copriva era
        // proprio l'unico (company_id, type) che qui viene rimosso: toglierlo
        // per primo da errore 1553 "needed in a foreign key constraint" e
        // ferma l'intera migrate. SQLite non ha questo requisito, quindi in dev
        // e nei test non si e' mai visto (B7, 31/08).
        //
        // Si lascia quindi un indice semplice su company_id PRIMA di togliere
        // l'unico. Niente drop e ricreazione della chiave esterna: MySQL ne
        // creerebbe comunque uno suo, e questo e' esplicito e con un nome noto.
        if (! SchemaIndex::exists('accounts', 'accounts_company_id_index')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->index('company_id', 'accounts_company_id_index');
            });
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'type']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('account_holder_type')->default('company')->after('company_id');
            $table->foreignId('managed_account_id')->nullable()->after('account_holder_type')->constrained('accounts')->nullOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->string('fiscal_code')->nullable()->after('phone');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
            $table->string('owner_type')->default('company')->after('owner_user_id');
            $table->foreignId('parent_account_id')->nullable()->after('owner_type')->constrained('accounts')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->after('parent_account_id')->constrained('users')->nullOnDelete();
            $table->string('account_name')->nullable()->after('type');
            $table->bigInteger('spending_limit')->nullable()->after('pending_balance');
            $table->bigInteger('daily_outgoing_limit')->nullable()->after('spending_limit');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
            $table->index(['owner_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['owner_type', 'status']);
            $table->foreignId('company_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('assigned_by_user_id');
            $table->dropConstrainedForeignId('parent_account_id');
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn(['owner_type', 'account_name', 'spending_limit', 'daily_outgoing_limit']);
            $table->unique(['company_id', 'type']);
        });

        // Ricreato l'unico, l'indice di servizio non serve piu' a coprire la FK.
        SchemaIndex::dropIfExists('accounts', 'accounts_company_id_index');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('managed_account_id');
            $table->dropColumn(['account_holder_type', 'phone', 'fiscal_code']);
        });
    }
};
