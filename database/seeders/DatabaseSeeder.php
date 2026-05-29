<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Announcement;
use App\Models\AnnouncementReply;
use App\Models\Company;
use App\Models\CreditLimit;
use App\Models\Listing;
use App\Models\Role;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferBookingService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $superAdminRole     = Role::query()->where('slug', 'system-superadmin')->firstOrFail();
        $companyManagerRole = Role::query()->where('slug', 'company-manager')->firstOrFail();
        $privateMemberRole  = Role::query()->where('slug', 'private-member')->firstOrFail();
        $delegateRole       = Role::query()->where('slug', 'delegate-member')->firstOrFail();
        $viewerRole         = Role::query()->where('slug', 'company-viewer')->firstOrFail();

        // Superadmin
        $superAdmin = User::create([
            'company_id'          => null,
            'account_holder_type' => 'company',
            'name'                => 'KMoney Superadmin',
            'email'               => 'superadmin@kmoney.test',
            'password'            => 'secret123',
            'role'                => 'system-superadmin',
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $superAdmin->forceFill(['email_verified_at' => now()])->save();
        $superAdmin->roles()->sync([$superAdminRole->id]);

        // 5 aziende
        $bakery  = $this->makeCompany('Panificio Canale', 'panificio-canale', 'alimentari', $companyManagerRole, 'company-manager', 15000, 12000);
        $farm    = $this->makeCompany('Azienda Agricola Selene', 'azienda-agricola-selene', 'alimentari', $companyManagerRole, 'company-manager', 22000, 18000);
        $studio  = $this->makeCompany('Studio Nord Ovest', 'studio-nord-ovest', 'consulenza', $companyManagerRole, 'company-manager', 18000, 14000);
        $tech    = $this->makeCompany('Nexova Tech', 'nexova-tech', 'informatica', $companyManagerRole, 'company-manager', 25000, 20000);
        $textile = $this->makeCompany('Tessuti Lombardi', 'tessuti-lombardi', 'artigianato', $companyManagerRole, 'company-manager', 12000, 9000);

        // Viewer su Panificio
        $viewer = User::create([
            'company_id'          => $bakery['company']->id,
            'account_holder_type' => 'company',
            'name'                => 'Panificio Viewer',
            'email'               => 'viewer-panificio@kmoney.test',
            'password'            => 'secret123',
            'role'                => 'company-viewer',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $viewer->forceFill(['email_verified_at' => now()])->save();
        $viewer->roles()->sync([$viewerRole->id]);

        // Membro privato + sottoconto
        $privateOwner = User::create([
            'company_id'          => null,
            'account_holder_type' => 'private',
            'name'                => 'Maria Ferri',
            'email'               => 'maria.ferri@kmoney.test',
            'password'            => 'secret123',
            'role'                => 'private-member',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $privateOwner->forceFill(['email_verified_at' => now()])->save();
        $privateOwner->roles()->sync([$privateMemberRole->id]);

        $privateAccount = Account::create([
            'company_id'             => null,
            'owner_user_id'          => $privateOwner->id,
            'owner_type'             => 'private',
            'type'                   => 'primary',
            'account_name'           => 'Conto personale Maria Ferri',
            'currency_code'          => 'KY',
            'status'                 => 'active',
            'allow_negative_balance' => false,
            'available_balance'      => 4200,
            'pending_balance'        => 0,
        ]);

        $familySubaccount = Account::create([
            'company_id'             => null,
            'owner_user_id'          => $privateOwner->id,
            'owner_type'             => 'private',
            'parent_account_id'      => $privateAccount->id,
            'assigned_by_user_id'    => $privateOwner->id,
            'type'                   => 'subaccount',
            'account_name'           => 'Budget Elisa',
            'currency_code'          => 'KY',
            'status'                 => 'active',
            'allow_negative_balance' => false,
            'available_balance'      => 600,
            'pending_balance'        => 0,
            'spending_limit'         => 150,
            'daily_outgoing_limit'   => 200,
        ]);

        $delegate = User::create([
            'company_id'          => null,
            'account_holder_type' => 'private',
            'managed_account_id'  => $familySubaccount->id,
            'name'                => 'Elisa Ferri',
            'email'               => 'elisa.ferri@kmoney.test',
            'password'            => 'secret123',
            'role'                => 'delegate-member',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $delegate->forceFill(['email_verified_at' => now()])->save();
        $delegate->roles()->sync([$delegateRole->id]);

        // Storico trasferimenti
        $this->seedTransferHistory($bakery, $farm, $studio, $tech, $textile);

        // Annunci + risposte
        $this->seedAnnouncements($bakery, $farm, $studio, $tech, $textile);

        // Vetrina listings
        $this->seedListings($bakery, $farm, $studio, $tech, $textile);

        // Riepilogo credenziali
        $this->command->info('');
        $this->command->info('  CREDENZIALI DEMO KMONEY');
        $this->command->info('  ----------------------------------------+------------');
        $this->command->info('  Email                                    | Password');
        $this->command->info('  ----------------------------------------+------------');
        $this->command->info('  superadmin@kmoney.test                   | secret123');
        $this->command->info('  operatore-panificio-canale@kmoney.test   | secret123');
        $this->command->info('  operatore-azienda-agricola-selene@kmoney.test | secret123');
        $this->command->info('  operatore-studio-nord-ovest@kmoney.test  | secret123');
        $this->command->info('  operatore-nexova-tech@kmoney.test        | secret123');
        $this->command->info('  operatore-tessuti-lombardi@kmoney.test   | secret123');
        $this->command->info('  viewer-panificio@kmoney.test             | secret123');
        $this->command->info('  maria.ferri@kmoney.test                  | secret123');
        $this->command->info('  elisa.ferri@kmoney.test                  | secret123');
        $this->command->info('  ----------------------------------------+------------');
        $this->command->info('');
    }

    // -------------------------------------------------------------------------

    private function makeCompany(
        string $name,
        string $slug,
        string $sector,
        Role   $role,
        string $roleLabel,
        int    $creditLimit,
        int    $dailyLimit
    ): array {
        $company = Company::create([
            'name'          => $name,
            'sector'        => $sector,
            'slug'          => $slug,
            'email'         => $slug . '@kmoney.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => $name . ' Operator',
            'email'               => 'operatore-' . $slug . '@kmoney.test',
            'password'            => 'secret123',
            'role'                => $roleLabel,
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->sync([$role->id]);

        $account = Account::create([
            'company_id'             => $company->id,
            'owner_user_id'          => $user->id,
            'owner_type'             => 'company',
            'type'                   => 'primary',
            'account_name'           => 'Conto principale ' . $name,
            'currency_code'          => 'KY',
            'status'                 => 'active',
            'allow_negative_balance' => true,
            'available_balance'      => 0,
            'pending_balance'        => 0,
        ]);

        CreditLimit::create([
            'account_id'            => $account->id,
            'credit_limit'          => $creditLimit,
            'daily_outgoing_limit'  => $dailyLimit,
            'single_transfer_limit' => (int) ($creditLimit * 0.5),
            'status'                => 'active',
        ]);

        return compact('company', 'account', 'user');
    }

    private function seedTransferHistory(array $bakery, array $farm, array $studio, array $tech, array $textile): void
    {
        $svc = app(TransferBookingService::class);

        $pairs = [
            [$bakery,  $farm,    3200, 'Fornitura farine e lieviti',              85],
            [$farm,    $studio,  2100, 'Consulenza amministrativa Q1',            80],
            [$studio,  $bakery,  1450, 'Servizi grafici e comunicazione',         75],
            [$tech,    $bakery,   900, 'Licenza software gestionale',             70],
            [$bakery,  $tech,     500, 'Sito e-commerce manutenzione',            65],
            [$farm,    $textile, 4100, 'Confezionamento prodotti agricoli',       60],
            [$textile, $studio,  1800, 'Catalogo collezione primavera',           55],
            [$studio,  $tech,    3300, 'Sviluppo app mobile',                     50],
            [$tech,    $farm,     700, 'Sensori IoT irrigazione',                 45],
            [$bakery,  $studio,  2400, 'Branding e packaging',                   40],
            [$farm,    $bakery,  1600, 'Materie prime biologiche',                35],
            [$textile, $bakery,   800, 'Uniformi personale',                      32],
            [$tech,    $studio,  2700, 'Consulenza UX/UI',                        28],
            [$studio,  $farm,    1200, 'Comunicazione fiera agricola',            24],
            [$bakery,  $textile,  950, 'Sacchetti personalizzati',                20],
            [$farm,    $tech,    1100, 'Progetto tracciabilita filiera',           16],
            [$textile, $tech,    2200, 'ERP su misura tessile',                   12],
            [$tech,    $textile,  450, 'Manutenzione server',                      8],
            [$studio,  $textile,  680, 'Shooting fotografico collezione',           4],
            [$bakery,  $farm,    1750, 'Ordine straordinario cereali',              1],
        ];

        foreach ($pairs as [$from, $to, $amount, $desc, $daysAgo]) {
            $transfer = $svc->book([
                'initiated_by'    => $from['user']->id,
                'from_account_id' => $from['account']->id,
                'to_account_id'   => $to['account']->id,
                'amount'          => $amount,
                'description'     => $desc,
                'idempotency_key' => (string) Str::uuid(),
                'ip_address'      => '127.0.0.1',
            ]);

            $bookedAt = Carbon::now()->subDays($daysAgo)->setHour(rand(8, 18))->setMinute(rand(0, 59));
            Transfer::where('id', $transfer->id)->update([
                'booked_at'  => $bookedAt,
                'created_at' => $bookedAt,
                'updated_at' => $bookedAt,
            ]);
        }
    }

    private function seedAnnouncements(array $bakery, array $farm, array $studio, array $tech, array $textile): void
    {
        $items = [
            [
                'company'       => $bakery,
                'type'          => 'offer',
                'sector'        => 'alimentari',
                'title'         => 'Fornitura pane artigianale settimanale',
                'body'          => 'Il Panificio Canale offre forniture settimanali di pane artigianale, grissini e prodotti da forno per ristoranti e mense aziendali aderenti al circuito KMoney. Minimo 20 KY a ordine. Consegna inclusa entro 30 km.',
                'reply_company' => $farm,
                'reply_msg'     => 'Siamo interessati per la nostra mensa aziendale. Potete gestire ordini ricorrenti con cadenza bisettimanale?',
            ],
            [
                'company'       => $farm,
                'type'          => 'offer',
                'sector'        => 'alimentari',
                'title'         => 'Verdure e legumi biologici a km zero',
                'body'          => "Offriamo cassette di verdure miste e legumi secchi BIO, coltivati in regime DOP. Ideale per ristoranti, hotel e catering nel circuito. Prezzi da 35 KY/cassetta. Disponibilita stagionale garantita.",
                'reply_company' => $bakery,
                'reply_msg'     => "Siamo interessati all'acquisto regolare di farine integrali. Avete anche farro e semola?",
            ],
            [
                'company'       => $studio,
                'type'          => 'offer',
                'sector'        => 'consulenza',
                'title'         => 'Consulenza fiscale e contabile per PMI',
                'body'          => 'Studio Nord Ovest offre servizi di consulenza fiscale, tenuta contabilita, dichiarazioni IVA e 730 per le aziende del circuito. Primo incontro gratuito. Pacchetti da 200 KY/mese.',
                'reply_company' => $tech,
                'reply_msg'     => 'Cerchiamo supporto per la gestione delle spese in R&D. Avete esperienza con startup tecnologiche?',
            ],
            [
                'company'       => $tech,
                'type'          => 'request',
                'sector'        => 'informatica',
                'title'         => 'Ricerca sviluppatore mobile React Native',
                'body'          => 'Nexova Tech cerca collaboratori freelance con esperienza in React Native per progetto 3 mesi. Compenso in KY, possibilita di continuazione. Inviare portfolio tramite messaggio privato.',
                'reply_company' => $studio,
                'reply_msg'     => 'Abbiamo un developer React Native disponibile. Vi mandiamo il CV via email aziendale?',
            ],
            [
                'company'       => $textile,
                'type'          => 'offer',
                'sector'        => 'artigianato',
                'title'         => 'Divise e abbigliamento da lavoro su misura',
                'body'          => 'Tessuti Lombardi realizza divise aziendali, grembiuli, polo personalizzate con logo. Lotti da 10 pezzi. Campionatura gratuita per ordini sopra 500 KY. Tessuti certificati OEKO-TEX.',
                'reply_company' => $bakery,
                'reply_msg'     => 'Perfetto! Avremmo bisogno di grembiuli con logo per 8 dipendenti. Come procediamo?',
            ],
        ];

        foreach ($items as $item) {
            $ann = Announcement::create([
                'company_id'         => $item['company']['company']->id,
                'created_by_user_id' => $item['company']['user']->id,
                'type'               => $item['type'],
                'title'              => $item['title'],
                'body'               => $item['body'],
                'sector'             => $item['sector'],
                'contact_info'       => $item['company']['company']->email,
                'status'             => 'active',
                'featured'           => false,
                'expires_at'         => now()->addMonths(3),
                'views_count'        => rand(8, 60),
            ]);

            AnnouncementReply::create([
                'announcement_id' => $ann->id,
                'user_id'         => $item['reply_company']['user']->id,
                'company_id'      => $item['reply_company']['company']->id,
                'message'         => $item['reply_msg'],
                'is_read'         => false,
            ]);
        }
    }

    private function seedListings(array $bakery, array $farm, array $studio, array $tech, array $textile): void
    {
        $listings = [
            [
                'company'     => $bakery,
                'title'       => 'Pane di grano duro 1 kg - fornitura settimanale',
                'description' => 'Pane artigianale di grano duro, cotto a legna, disponibile in forniture ricorrenti settimanali. Ideale per ristorazione.',
                'category'    => 'alimentari',
                'price_ky'    => 28,
            ],
            [
                'company'     => $bakery,
                'title'       => 'Grissini artigianali assortiti - cassetta 5 kg',
                'description' => 'Grissini torinesi, al rosmarino e integrali. Confezionamento personalizzabile per regalo aziendale.',
                'category'    => 'alimentari',
                'price_ky'    => 45,
            ],
            [
                'company'     => $farm,
                'title'       => 'Cassetta verdure miste BIO stagionali',
                'description' => 'Selezione settimanale di verdure biologiche a km zero. Peso 8-10 kg. Disponibile da aprile a ottobre.',
                'category'    => 'alimentari',
                'price_ky'    => 38,
            ],
            [
                'company'     => $farm,
                'title'       => 'Olio extravergine di oliva DOP 5 litri',
                'description' => 'Olio EVO prodotto in azienda, certificato DOP. Acidita sotto 0.2%. Disponibilita limitata - raccolta autunnale.',
                'category'    => 'alimentari',
                'price_ky'    => 120,
            ],
            [
                'company'     => $studio,
                'title'       => 'Pacchetto branding completo - logo + palette + manuale',
                'description' => 'Identita visiva completa per PMI: logo, varianti, palette colori, tipografia e brand manual in PDF. Consegna in 15 gg.',
                'category'    => 'marketing',
                'price_ky'    => 850,
            ],
            [
                'company'     => $studio,
                'title'       => 'Shooting fotografico prodotti - mezza giornata',
                'description' => 'Servizio fotografico professionale per catalogo, e-commerce o social. Include post-produzione e 30 foto consegnate.',
                'category'    => 'marketing',
                'price_ky'    => 320,
            ],
            [
                'company'     => $tech,
                'title'       => 'Sviluppo landing page + SEO on-page',
                'description' => 'Landing page responsive, ottimizzata SEO, integrazione form contatti e analytics. Consegna entro 10 giorni lavorativi.',
                'category'    => 'informatica',
                'price_ky'    => 480,
            ],
            [
                'company'     => $tech,
                'title'       => 'Assistenza IT mensile - piano base',
                'description' => 'Supporto remoto per workstation Windows/Mac, aggiornamenti, backup, gestione antivirus. Fino a 5 postazioni.',
                'category'    => 'informatica',
                'price_ky'    => 180,
            ],
            [
                'company'     => $textile,
                'title'       => 'Polo aziendali con ricamo logo - lotto 10 pz',
                'description' => 'Polo in piquet 100% cotone, ricamo logo fronte. Disponibili taglie XS-3XL. Campione gratuito su ordine confermato.',
                'category'    => 'artigianato',
                'price_ky'    => 220,
            ],
            [
                'company'     => $textile,
                'title'       => 'Grembiuli da lavoro personalizzati - lotto 5 pz',
                'description' => 'Grembiuli in canvas resistente con tasche e bretelle regolabili. Stampa o ricamo logo. Ideale per panetterie, bar, laboratori.',
                'category'    => 'artigianato',
                'price_ky'    => 95,
            ],
        ];

        foreach ($listings as $item) {
            Listing::create([
                'company_id'         => $item['company']['company']->id,
                'created_by_user_id' => $item['company']['user']->id,
                'title'              => $item['title'],
                'description'        => $item['description'],
                'category'           => $item['category'],
                'price_ky'           => $item['price_ky'],
                'images'             => [],
                'status'             => 'active',
                'featured'           => false,
                'contact_info'       => $item['company']['company']->email,
                'expires_at'         => now()->addMonths(6),
                'views_count'        => rand(5, 80),
            ]);
        }
    }
}
