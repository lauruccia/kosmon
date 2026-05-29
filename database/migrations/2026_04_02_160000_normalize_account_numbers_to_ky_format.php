<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Account::query()
            ->select(['id', 'uuid'])
            ->orderBy('id')
            ->each(function (Account $account): void {
                if (! Account::hasKyAccountNumber($account->uuid)) {
                    $account->forceFill([
                        'uuid' => 'KY' . str_pad((string) $account->id, 14, '0', STR_PAD_LEFT),
                    ])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        //
    }
};
