<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indice composito per la query anti-frode in TransferBookingService, eseguita
 * a OGNI transfer:
 *
 *   AuditLog::where('actor_user_id', $u)
 *           ->where('event', 'transfer.rejected')
 *           ->where('created_at', '>=', now()->subMinutes(5))->count();
 *
 * audit_logs è una tabella append-only che cresce all'infinito: senza questo
 * indice la query degenera in scansione. (actor_user_id, event, created_at)
 * la riduce a uno slice minimo.
 *
 * Idempotente e cross-database (SQLite dev / MySQL CI+prod) via Schema::getIndexes.
 */
return new class extends Migration
{
    private const INDEX = 'audit_logs_actor_event_created_index';

    public function up(): void
    {
        $existing = collect(Schema::getIndexes('audit_logs'))->pluck('name');

        if ($existing->contains(self::INDEX)) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['actor_user_id', 'event', 'created_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndexIfExists(self::INDEX);
        });
    }
};
