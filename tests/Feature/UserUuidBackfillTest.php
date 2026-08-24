<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `users.uuid` — l'identificativo pubblico introdotto con "Accedi con KMoney".
 *
 * Questo test non simula la migrazione: la esegue davvero, sul file vero
 * (`migrate:rollback --path=` + `migrate --path=`), così prova anche il `down()`
 * e il riempimento dello storico. È lo stesso metodo usato nella fase 0b, ed è
 * lì che si scopre in anticipo se in produzione la migrazione lascerebbe righe
 * senza uuid — cioè utenti che non potrebbero più fare login su kshop.
 */
class UserUuidBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_24_180000_add_uuid_to_users_table.php';

    public function test_ogni_nuovo_utente_nasce_con_il_suo_uuid(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $user->uuid
        );
    }

    public function test_due_utenti_non_hanno_mai_lo_stesso_uuid(): void
    {
        $primo   = User::factory()->create();
        $secondo = User::factory()->create();

        $this->assertNotSame($primo->uuid, $secondo->uuid);
    }

    public function test_la_migrazione_riempie_gli_utenti_gia_in_tabella(): void
    {
        // Si torna indietro: la colonna sparisce, come nel database di
        // produzione prima di questa release.
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => false]);

        // Utenti "storici", inseriti senza passare dal model (che l'uuid lo
        // metterebbe da solo): è la situazione vera del database.
        foreach (['storico-1@test.test', 'storico-2@test.test'] as $email) {
            DB::table('users')->insert([
                'name'                => 'Utente storico',
                'email'               => $email,
                'password'            => bcrypt('secret123'),
                'account_holder_type' => 'private',
                'role'                => 'private-owner',
                'is_active'           => true,
                'is_super_admin'      => false,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        Artisan::call('migrate', ['--path' => self::MIGRATION, '--realpath' => false]);

        $uuid1 = DB::table('users')->where('email', 'storico-1@test.test')->value('uuid');
        $uuid2 = DB::table('users')->where('email', 'storico-2@test.test')->value('uuid');

        $this->assertNotNull($uuid1, 'Il backfill deve dare un uuid anche a chi era già registrato.');
        $this->assertNotNull($uuid2);
        $this->assertNotSame($uuid1, $uuid2, 'Il backfill non deve assegnare lo stesso uuid a tutti.');
    }
}
