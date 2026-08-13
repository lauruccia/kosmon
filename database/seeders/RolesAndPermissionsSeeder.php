<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect([
            ['name' => 'Access backoffice', 'slug' => 'backoffice.access'],
            ['name' => 'Read users', 'slug' => 'users.read'],
            ['name' => 'Manage users', 'slug' => 'users.manage'],
            ['name' => 'Read roles', 'slug' => 'roles.read'],
            ['name' => 'Manage roles', 'slug' => 'roles.manage'],
            ['name' => 'Read companies', 'slug' => 'companies.read'],
            ['name' => 'Manage companies', 'slug' => 'companies.manage'],
            ['name' => 'Read accounts', 'slug' => 'accounts.read'],
            ['name' => 'Manage accounts', 'slug' => 'accounts.manage'],
            ['name' => 'Read movements', 'slug' => 'movements.read'],
            ['name' => 'Manage movements', 'slug' => 'movements.manage'],
            ['name' => 'Send payments', 'slug' => 'payments.send'],
            ['name' => 'Receive payments', 'slug' => 'payments.receive'],
            ['name' => 'Read announcements', 'slug' => 'announcements.read'],
            ['name' => 'Publish announcements', 'slug' => 'announcements.publish'],
            ['name' => 'Buy in marketplace', 'slug' => 'marketplace.buy'],
            ['name' => 'Sell in marketplace', 'slug' => 'marketplace.sell'],
            // 2026-08-12 (richiesta di Laura): ruolo "Gestore Aziende e Prodotti" — operatore di
            // backoffice ristretto a sole aziende/prodotti. backoffice.full segna chi ha accesso
            // pieno a TUTTE le sezioni del backoffice (vedi EnsureCanAccessBackoffice); chi ha solo
            // backoffice.access ma non backoffice.full viene filtrato in base ai permessi granulari
            // che possiede (companies.*, listings.*) — tutto il resto resta bloccato.
            ['name' => 'Full backoffice access', 'slug' => 'backoffice.full'],
            ['name' => 'Read shop listings', 'slug' => 'listings.read'],
            ['name' => 'Manage shop listings', 'slug' => 'listings.manage'],
        ])->mapWithKeys(fn (array $permission) => [
            $permission['slug'] => Permission::updateOrCreate(['slug' => $permission['slug']], $permission),
        ]);

        $superAdminRole = Role::updateOrCreate(
            ['slug' => 'system-superadmin'],
            ['name' => 'System Superadmin', 'scope' => 'system', 'description' => 'Controllo totale della piattaforma']
        );
        $backofficeRole = Role::updateOrCreate(
            ['slug' => 'backoffice-operator'],
            ['name' => 'Backoffice Operator', 'scope' => 'system', 'description' => 'Operatore interno con accesso alla control room']
        );
        $companyMemberRole = Role::updateOrCreate(
            ['slug' => 'company-member'],
            ['name' => 'Company Member', 'scope' => 'company', 'description' => 'Account holder aziendale con permessi base di incasso e pagamento']
        );
        $companyManagerRole = Role::updateOrCreate(
            ['slug' => 'company-manager'],
            ['name' => 'Company Manager', 'scope' => 'company', 'description' => 'Profilo aziendale con funzionalita business estese']
        );
        $privateMemberRole = Role::updateOrCreate(
            ['slug' => 'private-member'],
            ['name' => 'Private Member', 'scope' => 'private', 'description' => 'Profilo privato con invio e ricezione KMoney']
        );
        $delegateRole = Role::updateOrCreate(
            ['slug' => 'delegate-member'],
            ['name' => 'Delegate Member', 'scope' => 'delegate', 'description' => 'Delegato operativo su un sottoconto']
        );
        $viewerRole = Role::updateOrCreate(
            ['slug' => 'company-viewer'],
            ['name' => 'Company Viewer', 'scope' => 'company', 'description' => 'Profilo aziendale sola lettura']
        );
        // 2026-08-12 (richiesta di Laura): operatore di backoffice ristretto a sole aziende/prodotti
        // — puo' creare/modificare aziende (anagrafica, indirizzo, stato, piano, % KY, gateway di
        // pagamento, integrazione e-commerce, verifica KYC) e prodotti shop per conto delle aziende
        // (creazione, moderazione/sospensione, categorie), nient'altro a livello amministrativo
        // (niente utenti, ruoli, conti, movimenti, MLM, audit, impostazioni...). Vedi
        // EnsureCanAccessBackoffice per l'enforcement lato rotte.
        $companyListingsOperatorRole = Role::updateOrCreate(
            ['slug' => 'company-listings-operator'],
            ['name' => 'Gestore Aziende e Prodotti', 'scope' => 'system', 'description' => 'Operatore di backoffice limitato a gestione aziende e prodotti per conto delle aziende']
        );

        $superAdminRole->permissions()->sync($permissions->pluck('id')->all());
        $backofficeRole->permissions()->sync([
            $permissions['backoffice.access']->id,
            $permissions['backoffice.full']->id,
            $permissions['users.read']->id,
            $permissions['roles.read']->id,
            $permissions['companies.read']->id,
            $permissions['accounts.read']->id,
            $permissions['movements.read']->id,
            $permissions['movements.manage']->id,
        ]);
        $companyListingsOperatorRole->permissions()->sync([
            $permissions['backoffice.access']->id,
            $permissions['companies.read']->id,
            $permissions['companies.manage']->id,
            $permissions['listings.read']->id,
            $permissions['listings.manage']->id,
        ]);
        $companyMemberRole->permissions()->sync([
            $permissions['payments.send']->id,
            $permissions['payments.receive']->id,
            $permissions['movements.read']->id,
        ]);
        $companyManagerRole->permissions()->sync([
            $permissions['payments.send']->id,
            $permissions['payments.receive']->id,
            $permissions['movements.read']->id,
            $permissions['accounts.read']->id,
            $permissions['accounts.manage']->id,
            $permissions['users.read']->id,
            $permissions['users.manage']->id,
            $permissions['companies.read']->id,
            $permissions['announcements.read']->id,
            $permissions['announcements.publish']->id,
            $permissions['marketplace.buy']->id,
            $permissions['marketplace.sell']->id,
        ]);
        $privateMemberRole->permissions()->sync([
            $permissions['payments.send']->id,
            $permissions['payments.receive']->id,
            $permissions['movements.read']->id,
            $permissions['companies.read']->id,
        ]);
        $delegateRole->permissions()->sync([
            $permissions['payments.send']->id,
            $permissions['payments.receive']->id,
            $permissions['movements.read']->id,
        ]);
        $viewerRole->permissions()->sync([
            $permissions['companies.read']->id,
            $permissions['movements.read']->id,
        ]);
    }
}
