<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $accounts = DB::table('accounts')
            ->where('owner_type', 'company')
            ->whereNull('owner_user_id')
            ->whereNotNull('company_id')
            ->get(['id', 'company_id']);

        foreach ($accounts as $account) {
            $ownerUserId = DB::table('users')
                ->where('company_id', $account->company_id)
                ->orderBy('id')
                ->value('id');

            if ($ownerUserId !== null) {
                DB::table('accounts')
                    ->where('id', $account->id)
                    ->update([
                        'owner_user_id' => $ownerUserId,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Backfill irreversibile: non ripristino owner_user_id a null.
    }
};
