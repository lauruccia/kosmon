<?php

namespace App\Http\Controllers;

use App\Models\AgentCodeFeePayment;
use App\Models\CompanyAccountFeePayment;
use App\Models\RegistrationFeePayment;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LE TRE QUOTE IN UNA PAGINA SOLA (richiesta di Laura del 04/09/2026:
 * «un'unica pagina dove attivare o disattivare le 3 quote, impostare
 * l'importo, metodi di pagamento, fido ed eventuale restituzione in ky per chi
 * paga in euro con importo deciso da me. Semplifichiamo»).
 *
 * Fino a ieri le quote avevano tre pagine identiche in tutto tranne i nomi, e
 * ogni correzione andava fatta tre volte — che e' esattamente il difetto che
 * il 02/09 aveva gia' obbligato a fondere i servizi in un motore comune. Qui
 * succede la stessa cosa alle pagine: la descrizione delle tre quote e' un
 * array, e la vista la scorre.
 *
 * QUESTO CONTROLLER NON SCRIVE NIENTE. I tre form salvano ognuno sulla propria
 * rotta `admin.*-fees.settings`, che sono rimaste dove stavano: sono quelle che
 * conoscono le regole della loro quota (il rifiuto ad accendere una quota senza
 * metodi, o con importo zero) e hanno i test addosso da giorni. Tre bottoni
 * «Salva» invece di uno non e' una mancanza: salvare una quota non deve poter
 * toccare le altre due, e un errore su una lascia le altre come stavano.
 *
 * ANCHE GLI ELENCHI DEI PAGAMENTI SONO QUI, in tre schede. La scheda e' un
 * parametro nell'indirizzo (`?tab=`) e non una scorciatoia in JavaScript: cosi'
 * i link della paginazione e il filtro per stato se la portano dietro, e si
 * carica una lista sola invece di tre.
 */
class QuoteAdminController extends Controller
{
    /**
     * Le tre quote. Tutto cio' che le distingue sta qui dentro, nello stesso
     * spirito di FeeDefinition per il motore: aggiungere una quarta quota vuol
     * dire aggiungere una voce, non un'altra pagina.
     */
    private const QUOTE = [
        'privati' => [
            'titolo'          => 'Quota di iscrizione — privati',
            'prefisso'        => 'registration_fee',
            'campo_importo'   => 'registration_fee_amount',
            'campo_credito'   => 'registration_fee_ky_credit',
            'rotta_salva'     => 'admin.registration-fees.settings',
            'rotta_elenco'    => 'admin.registration-fees.index',
            'rotta_conferma'  => 'admin.registration-fees.confirm',
            'rotta_rifiuta'   => 'admin.registration-fees.reject',
            'rotta_annulla'   => 'admin.registration-fees.cancel',
            'rotta_ripesca'   => 'admin.registration-fees.retry-credit',
            'classe'          => RegistrationFeePayment::class,
            'chi'             => 'Utente',
            'blocca_conto'    => true,
        ],
        'agenti' => [
            'titolo'          => 'Quota per il codice agente',
            'prefisso'        => 'agent_code_fee',
            'campo_importo'   => 'agent_code_fee_amount',
            'campo_credito'   => 'agent_code_fee_ky_credit',
            'rotta_salva'     => 'admin.agent-code-fees.settings',
            'rotta_elenco'    => 'admin.agent-code-fees.index',
            'rotta_conferma'  => 'admin.agent-code-fees.confirm',
            'rotta_rifiuta'   => 'admin.agent-code-fees.reject',
            'rotta_annulla'   => 'admin.agent-code-fees.cancel',
            'rotta_ripesca'   => 'admin.agent-code-fees.retry-credit',
            'classe'          => AgentCodeFeePayment::class,
            'chi'             => 'Aspirante agente',
            'blocca_conto'    => false,
        ],
        'aziende' => [
            'titolo'          => 'Quota di apertura conto — aziende',
            'prefisso'        => 'company_account_fee',
            'campo_importo'   => 'company_account_fee_amount',
            'campo_credito'   => 'company_account_fee_ky_credit',
            'rotta_salva'     => 'admin.company-account-fees.settings',
            'rotta_elenco'    => 'admin.company-account-fees.index',
            'rotta_conferma'  => 'admin.company-account-fees.confirm',
            'rotta_rifiuta'   => 'admin.company-account-fees.reject',
            'rotta_annulla'   => 'admin.company-account-fees.cancel',
            'rotta_ripesca'   => 'admin.company-account-fees.retry-credit',
            'classe'          => CompanyAccountFeePayment::class,
            'chi'             => 'Azienda',
            'blocca_conto'    => false,
        ],
    ];

    /**
     * Il limite giornaliero di uscita con cui nasce ogni conto
     * (Account::booted). Una quota in KY che lo supera non passa, e l'azienda
     * o l'utente leggono «hai raggiunto il limite giornaliero» senza capire
     * perche': Laura ha deciso il 04/09 di NON esentare le quote dai limiti di
     * spesa, quindi l'unica difesa e' dirlo qui, prima che succeda.
     */
    private const LIMITE_GIORNALIERO_DI_FABBRICA = 50000;

    public function index(Request $request): View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $tab = $request->string('tab')->toString();
        if (! array_key_exists($tab, self::QUOTE)) {
            $tab = 'privati';
        }

        $stato    = $request->string('stato')->toString();
        $settings = SystemSetting::userLimitDefaults();

        $quote = [];
        foreach (self::QUOTE as $chiave => $conf) {
            $quote[$chiave] = $this->descrivi($chiave, $conf, $settings);
        }

        // Solo la lista della scheda aperta: le altre due non si guardano, e
        // caricarle sarebbe tre query e tre paginatori per niente.
        $classe   = self::QUOTE[$tab]['classe'];
        $payments = $classe::query()
            ->with(['user.company', 'confirmer'])
            ->when($stato !== '', fn ($q) => $q->where('status', $stato))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.quote', [
            'pageTitle' => 'Quote del circuito',
            'activeNav' => 'quote',
            'settings'  => $settings,
            'quote'     => $quote,
            'tab'       => $tab,
            'stato'     => $stato,
            'payments'  => $payments,
        ]);
    }

    /**
     * Le tre vecchie pagine (/admin/quote-iscrizione, /admin/quote-codice-agente,
     * /admin/quote-apertura-conto) portano qui, sulla scheda giusta.
     *
     * Le rotte non sono state cancellate apposta: ci puntano i link della
     * scheda utente, i rimandi dei tre controller dopo ogni azione e i
     * segnalibri di chi le usa da giorni. Un redirect costa un giro in piu' e
     * non lascia indietro nessuno.
     */
    public function vecchiaPrivati(Request $request)
    {
        return $this->vecchiaPagina($request, 'privati');
    }

    public function vecchiaAgenti(Request $request)
    {
        return $this->vecchiaPagina($request, 'agenti');
    }

    public function vecchiaAziende(Request $request)
    {
        return $this->vecchiaPagina($request, 'aziende');
    }

    private function vecchiaPagina(Request $request, string $tab)
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        return redirect()->route('admin.quote.index', array_filter([
            'tab'   => $tab,
            'stato' => $request->string('stato')->toString(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $conf
     * @return array<string, mixed>
     */
    private function descrivi(string $chiave, array $conf, SystemSetting $settings): array
    {
        $prefisso = $conf['prefisso'];

        // I quattro metodi. Il secondo valore dice se il circuito puo' davvero
        // eseguirlo: senza le chiavi Stripe o l'IBAN in configurazione, un
        // metodo acceso resta comunque nascosto agli utenti, e va detto qui
        // invece di lasciar credere che sia attivo.
        $metodi = [
            $prefisso . '_stripe_enabled'        => ['Carta (Stripe)', (bool) config('services.stripe.secret')],
            $prefisso . '_paypal_enabled'        => ['PayPal', (bool) config('services.paypal.client_id')],
            $prefisso . '_bank_transfer_enabled' => ['Bonifico bancario', (bool) config('kmoney.bank_iban')],
            $prefisso . '_ky_enabled'            => ['Saldo KY', true],
        ];

        $importo = max(0, (int) ($settings->{$prefisso . '_amount_cents'} ?? 0));

        return array_merge($conf, [
            'chiave'   => $chiave,
            'metodi'   => $metodi,
            'importo'  => $importo,
            'credito'  => max(0, (int) ($settings->{$prefisso . '_ky_credit_cents'} ?? 0)),
            // Sul pannello il NULL vale ACCESO, come nel motore: e' il
            // comportamento storico, non l'assenza di una scelta.
            'fido'     => $settings->{$prefisso . '_ky_allowance'} === null
                || (bool) $settings->{$prefisso . '_ky_allowance'},
            'accesa'   => (bool) $settings->{$prefisso . '_enabled'},
            // Diverso da 'accesa': questa e' la quota che sta DAVVERO
            // funzionando, interruttore piu' importo maggiore di zero piu'
            // almeno un metodo eseguibile. E' quello che vede l'utente.
            'attiva'   => $this->attivaDavvero($chiave, $settings),
            // Una quota in KY sopra il limite giornaliero di fabbrica non si
            // riesce a pagare, e nessun messaggio d'errore lo spiega.
            'oltre_il_limite' => $importo > self::LIMITE_GIORNALIERO_DI_FABBRICA,
            'limite_giornaliero' => self::LIMITE_GIORNALIERO_DI_FABBRICA,
        ]);
    }

    private function attivaDavvero(string $chiave, SystemSetting $settings): bool
    {
        return match ($chiave) {
            'privati' => $settings->registrationFeeEnabled(),
            'agenti'  => $settings->agentCodeFeeEnabled(),
            'aziende' => $settings->companyAccountFeeEnabled(),
            // Le chiavi sono tre e sono scritte qui sopra in QUOTE, ma il
            // match vuole un ramo per tutto il resto: se un giorno ne
            // comparisse una quarta senza il suo interruttore, meglio
            // vederla spenta che vedere la pagina esplodere.
            default   => false,
        };
    }
}
