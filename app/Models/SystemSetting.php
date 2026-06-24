<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $code
 * @property int|null $default_circuit_capacity_limit
 * @property int|null $default_negative_balance_limit
 * @property int|null $default_daily_transaction_limit
 * @property int|null $default_monthly_transaction_limit
 * @property int|null $default_per_movement_limit
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $circuit_name
 * @property string|null $circuit_tagline
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $website_url
 * @property string|null $logo_path
 * @property string $primary_color
 * @property string $accent_color
 * @property string|null $footer_text
 * @property bool $contract_force_sign
 * @property \Illuminate\Support\Carbon|null $contract_required_from
 * @property string|null $contract_text
 * @property int $contract_version
 * @property int|null $payment_confirm_totp_threshold
 * @property int|null $payment_pin_threshold
 * @property int $welcome_bonus_amount
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereAccentColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereCircuitName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereCircuitTagline($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereContactEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereContactPhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereContractForceSign($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereContractRequiredFrom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereContractText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereContractVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereDefaultCircuitCapacityLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereDefaultDailyTransactionLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereDefaultMonthlyTransactionLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereDefaultNegativeBalanceLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereDefaultPerMovementLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereFooterText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereLogoPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting wherePaymentConfirmTotpThreshold($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting wherePaymentPinThreshold($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting wherePrimaryColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereWebsiteUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SystemSetting whereWelcomeBonusAmount($value)
 * @mixin \Eloquent
 */
class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'circuit_name',
        'circuit_tagline',
        'contact_email',
        'contact_phone',
        'website_url',
        'logo_path',
        'primary_color',
        'accent_color',
        'footer_text',
        'default_circuit_capacity_limit',
        'default_negative_balance_limit',
        'default_daily_transaction_limit',
        'default_monthly_transaction_limit',
        'default_per_movement_limit',
        'payment_confirm_totp_threshold',
        'payment_pin_threshold',
        'welcome_bonus_amount',
        'contract_force_sign',
        'contract_required_from',
        'contract_text',
        'contract_version',
    ];

    protected function casts(): array
    {
        return [
            'contract_force_sign'    => 'boolean',
            'contract_required_from' => 'date',
        ];
    }

    // ── Contract settings ─────────────────────────────────────────────────────

    public static function contractSettings(): self
    {
        return static::query()->firstOrCreate(
            ['code' => 'contract'],
            [
                'contract_force_sign'    => false,
                'contract_required_from' => now()->toDateString(),
                'contract_text'          => null,
                'contract_version'       => 1,
            ]
        );
    }

    /**
     * Restituisce il testo del contratto con i placeholder sostituiti dai dati dell'azienda.
     *
     * Placeholder disponibili:
     *   {{ragione_sociale}}, {{partita_iva}}, {{codice_fiscale}}, {{settore}},
     *   {{citta}}, {{telefono}}, {{email}}, {{sito_web}}, {{nome_rappresentante}},
     *   {{uuid_azienda}}, {{data_firma}}
     */
    public function renderContractText(?Company $company, \App\Models\User $user): string
    {
        $text = $this->contract_text ?? self::defaultContractText();

        $map = [
            '[[ragione_sociale]]'    => e($company?->name ?? ''),
            '[[partita_iva]]'        => e($company?->vat_number ?? ''),
            '[[codice_fiscale]]'     => e($company?->fiscal_code ?? ''),
            '[[settore]]'            => e($company?->sector ?? ''),
            '[[citta]]'              => e($company?->city ?? ''),
            '[[telefono]]'           => e($company?->phone ?? ''),
            '[[email]]'              => e($company?->email ?? $user->email ?? ''),
            '[[sito_web]]'           => e($company?->website ?? ''),
            '[[nome_rappresentante]]'=> e($user->name ?? ''),
            '[[uuid_azienda]]'       => e($company?->uuid ?? ''),
            '[[data_firma]]'         => now()->format('d/m/Y'),
        ];

        return str_replace(array_keys($map), array_values($map), $text);
    }

    public static function defaultContractText(): string
    {
        return <<<'HTML'
<p><strong>KOSMOS NETWORK MARKETING — Contratto di adesione al Circuito KSM</strong></p>
<p>Il Gestore del Circuito è <strong>KOSMOS S.r.l.</strong>, con sede legale in C.so Vittorio Emanuele II, 36 — 62032 Camerino (MC), C.F./P.IVA 01768560433, Reg. Imprese MC-179933, Codice Univoco M5UXCR1, PEC e contatti come indicati sul Portale. Con la sottoscrizione della richiesta di adesione il Cliente dichiara di aver letto e accettato integralmente le seguenti condizioni generali.</p>

<h2>Informativa e consenso ai sensi del D.Lgs 196/03</h2>
<p>In relazione ai trattamenti di dati personali conseguenti l'esecuzione del presente contratto, la Kosmos S.r.l., Titolare ai sensi del D.Lgs 196/03, precisa quanto segue:</p>
<p>a) I dati forniti saranno utilizzati dal Titolare solo per le finalità contrattuali sopra dichiarate e concordate nonché per tutti gli adempimenti collegati.<br>
b) Essi potranno essere trattati con modalità cartacee e con strumenti elettronici, e potranno, se previsto tra le finalità, essere comunicati e diffusi a terzi, quali organi istituzionali, enti, società, collaboratori e professionisti, sempre in conformità con la finalità espressa.<br>
c) Il conferimento di quanto sopra richiesto è facoltativo per il perseguimento della finalità predetta e la mancanza o la successiva revoca del consenso comporterà per il Titolare l'impossibilità di svolgere i suddetti trattamenti e di perseguire le finalità concordate.<br>
d) Titolare del trattamento dei dati è la Kosmos S.r.l., avente sede legale a Camerino in C.so Vittorio Emanuele II, 36 — 62032, presso cui si potrà rivolgere in qualunque momento per l'esercizio dei suoi diritti ai sensi dell'articolo 7 del D.Lgs 196/03: diritto in qualunque momento di ottenere la conferma dell'esistenza o meno dei medesimi dati, di conoscerne il contenuto, l'origine, di verificarne l'esattezza e/o chiederne l'integrazione e/o l'aggiornamento, oppure la rettificazione.</p>

<hr>
<p><strong>Condizioni generali del contratto di fornitura servizi</strong></p>

<h2>Art. 1 – Termini e condizioni</h2>
<p>Nel testo del presente contratto si intende indicare con il termine <strong>Kosmos</strong> la società Kosmos S.r.l. domiciliata presso la propria sede legale, società che gestisce l'intero Circuito KSM. <strong>KSM</strong>: il Circuito che permette ai propri Clienti di effettuare acquisti e vendite anche con il meccanismo degli scambi multilaterali attraverso operazioni in compensazione. <strong>Portale</strong>: il sito del Circuito. <strong>Cliente</strong>: la persona fisica o giuridica che richiede di usufruire dei servizi oggetto del presente contratto. <strong>Estratto Conto</strong>: l'insieme delle registrazioni, tenute a cura di Kosmos, degli acquisti e delle vendite effettuate da ciascun Cliente all'interno del Circuito. <strong>Kmoney</strong>: l'unità di conto, il cui valore è pari ad un euro, utilizzata all'interno del Circuito per indicare il valore degli acquisti e delle vendite. <strong>App</strong>: l'applicazione che consente di fruire dei servizi offerti utilizzando telefoni cellulari smartphone.</p>

<h2>Art. 2 – I servizi di Kosmos</h2>
<p>Kosmos offre al Cliente servizi e supporti finalizzati a fornire la possibilità di effettuare acquisti o vendite di beni e servizi da e ad altri Clienti tramite scambi multilaterali in compensazione e a tal fine: a) mette a disposizione dei Clienti un Portale con elencate le Aziende suddivise per categoria; b) inserisce ogni Cliente nel Portale, rendendo così l'adesione al Circuito più visibile; c) mette a disposizione delle Aziende uno spazio, all'interno del Portale, per allestire una vetrina virtuale con la propria presentazione, i beni e i servizi offerti; d) coordina le offerte di vendita e gli ordini di acquisto ricevuti; e) redige l'estratto conto ed aggiorna il saldo contabile di ciascun Cliente in base alle operazioni di acquisto e/o vendita effettuate e lo rende disponibile al Cliente, nella sezione all'interno dell'area riservata; f) notifica nell'area riservata le richieste di acquisto di beni o servizi che altri Clienti dovessero effettuare; g) consente a ciascun Cliente di effettuare richieste di acquisto, promozioni e offerte speciali; h) aggiorna i Clienti su nuove attività disponibili all'interno del Portale.</p>

<h2>Art. 3 – Corrispettivi</h2>
<p>Il Cliente riconoscerà a Kosmos un canone fisso annuale anticipato e un compenso in percentuale sul venduto così come quantificato nella richiesta di adesione.</p>

<h2>Art. 4 – Responsabilità del Cliente</h2>
<p>a) Acquisti e vendite realizzati tramite KSM avvengono direttamente tra un Cliente e l'altro e pertanto Kosmos non è mai acquirente o fornitore e non fornisce alcuna garanzia in merito ad eventuali vizi o alla qualità delle forniture; b) il Cliente che effettua una vendita si impegna ad eseguirla a regola d'arte, in conformità a quanto stabilito dalla legge e dagli usi e comunque alle medesime condizioni economiche normalmente praticate al di fuori di KSM; c) ciascun Cliente è responsabile degli atti e fatti a lui ascrivibili nell'ambito del Circuito, pertanto Kosmos non potrà mai essere ritenuta responsabile e non risponderà di eventuali danni a questi ascrivibili; in ogni caso ciascun Cliente esonera espressamente Kosmos e i suoi collaboratori da ogni responsabilità che possa emergere a riguardo; d) ferma la responsabilità diretta del Cliente in merito a ciascuna vendita effettuata, questi manleverà Kosmos da ogni perdita, danno, responsabilità, costo, onere o spesa, ivi comprese le spese legali, che dovessero essere subite o sostenute in relazione ad una fornitura, nonché da ogni pretesa di risarcimento danni avanzata da terzi o da altro Cliente nei confronti di Kosmos quale conseguenza diretta o indiretta del comportamento del Cliente stesso.</p>

<h2>Art. 5 – Unità di conto</h2>
<p>I Kmoney indicano esclusivamente il valore degli acquisti e delle vendite effettuate tramite KSM. Kosmos non agisce quale istituto di credito, i Kmoney non sono rappresentativi di depositi bancari, di valuta corrente o di titoli, ancorché rappresentativi di merci, non possono essere trasformati in denaro e non producono interessi. In nessun caso il Cliente potrà chiedere a Kosmos la conversione in valuta corrente delle unità di conto indicate dal saldo contabile dell'Estratto Conto.</p>

<h2>Art. 6 – Estratto Conto</h2>
<p>a) Le operazioni di acquisto e di vendita effettuate da ciascun Cliente saranno trascritte a cura di Kosmos nell'Estratto Conto del Cliente stesso disponibile nell'area riservata del Portale. Trascorsi 15 giorni dall'aggiornamento dell'Estratto Conto del Cliente senza che questi abbia denunciato a Kosmos eventuali inesattezze, la relativa posizione contabile sarà considerata accettata; b) l'Estratto Conto del Cliente sarà aggiornato solo successivamente alla conclusione della vendita ed una volta adempiute tutte le formalità indicate nel successivo articolo "Procedure di acquisto e di vendita".</p>

<h2>Art. 7 – Procedure di acquisto e di vendita</h2>
<p>a) I Clienti negozieranno liberamente tra di loro ed in assoluta autonomia i termini di ciascuna fornitura; b) raggiunto l'accordo relativo ad una fornitura ed alla percentuale di compensazione che potrà essere pari a 0%, 25%, 50%, 75%, 100%, al fine di rendere possibile il pagamento in compensazione, l'acquirente dovrà accedere alla apposita sezione del Portale o dell'App e compilare il modulo di pagamento con il nominativo del venditore, del corrispettivo totale della compravendita e dell'importo da pagarsi in Kmoney attraverso il Portale. Dopo aver inserito i dati indicati, dovrà confermare l'operazione selezionando il tasto di conferma e inserendo il proprio codice univoco (password); c) Kosmos invierà ad acquirente e fornitore un messaggio riepilogativo della transazione, con indicazione del valore totale della fornitura e dell'importo in Kmoney; d) la ricezione dell'importo da parte del venditore determinerà la conclusione della transazione; e) venditore e acquirente riceveranno notifica dell'avvenuta transazione; f) ferme le procedure di acquisto e di vendita di cui al precedente punto 7.a., il venditore potrà richiedere che il corrispettivo di ciascuna vendita venga regolato dall'acquirente per la parte prevista in Kmoney attraverso il Portale, mentre per la rimanente parte venga saldato con i termini e le modalità pattuite di concerto con l'acquirente; g) qualora il venditore reputi conveniente evitare di pattuire con l'acquirente di volta in volta per ciascuna vendita la porzione di pagamento richiesta in Kmoney, potrà predefinire in percentuale fissa tale ammontare. In tal caso la percentuale in Kmoney proposta dal venditore sarà pubblicata all'interno dell'area riservata del Portale e nell'App; h) la percentuale in Kmoney definita ai sensi del punto precedente sarà applicata su ciascuna vendita effettuata dal Cliente sino ad eventuale modifica. Tale modifica avrà efficacia immediatamente. Non è previsto alcun limite al numero di variazioni che è possibile richiedere in vigenza di adesione; i) completata la procedura di acquisto e di vendita ed accreditato il relativo ammontare di Kmoney nella posizione contabile del Cliente che ha effettuato la vendita, questi rinuncia espressamente a qualunque azione diretta nei confronti dell'acquirente per ottenere il pagamento dell'importo accreditatogli in Kmoney; l) per ciascuna fornitura, il fornitore emetterà regolare documento fiscale nei confronti dell'acquirente.</p>

<h2>Art. 8 – Limite di spesa</h2>
<p>Al fine di consentire al Cliente la possibilità di effettuare acquisti, anche prima di aver effettuato vendite, Kosmos potrà concedere al Cliente stesso la possibilità di effettuare acquisti attraverso KSM anche senza avere nella propria Posizione Contabile la disponibilità di Kmoney derivanti da precedenti vendite. Tale possibilità sarà compresa entro un dato limite di spesa che Kosmos si riserva di concedere a ciascun Cliente. La capacità di acquisto in compensazione di ciascun Cliente all'interno del Circuito è data da tale importo a cui va di volta in volta aggiunto il valore totale delle vendite e detratto il valore totale degli acquisti effettuati in compensazione. La concessione del limite di spesa è subordinata all'esito positivo dell'istruttoria che Kosmos eseguirà circa l'affidabilità del Cliente e pertanto resta inteso che Kosmos potrà sempre accettare la sottoscrizione dell'adesione da parte del Cliente anche assegnandogli un limite di spesa pari a zero Kmoney. In tal caso il Cliente potrà effettuare operazioni in acquisto solo se dal suo Estratto Conto risulti un saldo contabile positivo. Il limite di spesa concesso potrà sempre essere modificato o revocato qualora Kosmos ritenga, a suo insindacabile giudizio, cambiati o venuti meno i requisiti riscontrati al momento della sua eventuale concessione.</p>

<h2>Art. 9 – Compensazione</h2>
<p>Qualora a seguito dell'utilizzo del limite di spesa eventualmente concesso, l'Estratto Conto del Cliente evidenzi un saldo contabile negativo, il Cliente stesso sarà tenuto ad eseguire nei confronti di altri Clienti che ne facciano richiesta e di volta in volta indicati da Kosmos una o più vendite in compensazione 100%, fino al pareggio della propria posizione contabile. Nonostante quanto previsto al paragrafo che precede, qualora l'Estratto Conto del Cliente evidenzi un saldo contabile negativo, il Cliente dovrà pareggiare immediatamente la propria posizione contabile, versando in denaro un importo equivalente al proprio debito di Kmoney direttamente a Kosmos nei seguenti casi: il Cliente si rifiuti di eseguire le vendite all'interno del Circuito come richieste da Kosmos; il Cliente non compensi, per qualsivoglia motivo, un debito per acquisti effettuati nel termine di 12 mesi a decorrere dalla conclusione dell'operazione; gli effetti del contratto di adesione al Circuito di Kosmos vengano meno per disdetta, recesso o per qualsivoglia altra causa; il contratto venga disdetto dal Cliente, ovvero disattivato ad opera di Kosmos per il mancato pagamento dei corrispettivi di cui all'articolo 3. Una volta ricevuto il pagamento Kosmos provvederà ad annotare il relativo versamento nell'Estratto Conto del Cliente liberandolo. A fronte delle somme ricevute ai sensi del capoverso precedente, nei limiti dell'importo effettivamente ricevuto, Kosmos provvederà ad immettere nel Circuito prodotti e servizi per un valore equivalente.</p>

<h2>Art. 10 – Accettazione del Contratto</h2>
<p>Il rapporto tra Kosmos e il Cliente è regolato dalle presenti condizioni generali di contratto, nonché dalla richiesta di adesione debitamente compilata dal richiedente ed inviata a Kosmos, anche in modalità telematica. Kosmos accetterà le richieste di adesione pervenute a sua sola ed insindacabile discrezione, comunicando al richiedente l'eventuale non accettazione a mezzo PEC o altro strumento equivalente.</p>

<h2>Art. 11 – Durata del contratto</h2>
<p>Il presente contratto e quindi l'obbligo di rispettarne tutte le clausole ha effetto a partire dal suo perfezionamento. Il contratto si intenderà valido sin dalla data della sua sottoscrizione da parte del Cliente così come riportato in frontespizio, qualora entro giorni 60 dalla sottoscrizione dello stesso la Kosmos non abbia manifestato la volontà di non accettarlo. Il presente contratto avrà la durata di mesi 12, se non diversamente indicato in frontespizio, e si rinnoverà, in mancanza di disdetta inviata 60 giorni prima della scadenza tramite raccomandata A/R o PEC, per un ulteriore periodo di pari durata e senza soluzione di continuità, e analogamente avverrà ad ogni successiva scadenza.</p>

<h2>Art. 12 – Risoluzione anticipata</h2>
<p>Kosmos ha la facoltà di risolvere anticipatamente il presente contratto senza necessità di preavviso o di preventiva costituzione in mora quando: a carico del Cliente sia stata richiesta l'apertura di una procedura concorsuale; il Cliente abbia cessato la sua attività o versi in stato di liquidazione; il Cliente sia incorso nella violazione anche di una sola delle clausole previste dai seguenti articoli: articolo 3 (corrispettivi), articolo 4 (responsabilità del Cliente), articolo 9 (compensazione); siano venuti meno, ad insindacabile giudizio di Kosmos, i requisiti del Cliente riscontrati all'atto dell'accettazione della richiesta di adesione; il Cliente non abbia comunicato a mezzo PEC o lettera A/R la modifica della propria struttura giuridica, lo spostamento della sede, la cessione d'azienda o di ramo d'azienda.</p>

<h2>Art. 13 – Effetti del recesso o dell'estinzione</h2>
<p>Resta inteso che qualora al momento della cessazione degli effetti del presente contratto, da qualsivoglia causa determinata, risulti che il Cliente abbia conservato una provvista di Kmoney, questa continuerà a sussistere come credito di fornitura; qualora però tale provvista non venga utilizzata dal Cliente per un periodo di anni 1 si intenderà rinunciata e null'altro potrà essere chiesto dal Cliente a Kosmos, KSM o ai singoli aderenti a KSM. In nessun caso il Cliente potrà chiedere a Kosmos di integrare detta provvista con denaro.</p>

<h2>Art. 14 – Modifiche del contratto</h2>
<p>Kosmos ha il diritto di modificare o integrare in qualunque momento le presenti condizioni generali di contratto, il contenuto del suo sito internet, nonché il contenuto e le modalità di erogazione di uno o più servizi. In tali casi ogni modifica verrà comunicata al Cliente a mezzo posta elettronica certificata all'indirizzo indicato nella richiesta di adesione. Qualora il Cliente non intenda aderire alle modifiche dovrà comunicare a mezzo raccomandata A/R o a mezzo PEC il proprio recesso entro quindici (15) giorni dalla comunicazione; trascorso tale termine ogni modifica verrà considerata accettata. Qualsivoglia modifica, deroga o integrazione alle condizioni particolari di contratto convenuta tra le parti dovrà essere provata per iscritto.</p>

<h2>Art. 15 – Clausola arbitrale</h2>
<p>Qualora dovessero sorgere controversie tra venditore e acquirente, gli stessi si impegnano a regolare i rapporti tra loro sorti facendo ricorso ad un arbitro nominato annualmente da Kosmos il quale, sentite le parti e i loro consulenti se necessario, deciderà senza formalità. La decisione dell'arbitro è vincolante tra le parti qualora le stesse ne sottoscrivano per accettazione la decisione resa. Resta inteso che le parti, esperita tale procedura, saranno libere di adire l'autorità giudiziaria.</p>

<h2>Art. 16 – Elezione di domicilio</h2>
<p>Il Cliente elegge domicilio ad ogni effetto presso la sede indicata nel contratto. Eventuali variazioni del domicilio non avranno effetto e non potranno essere opposte a Kosmos fino a che non siano state comunicate a mezzo lettera raccomandata A/R o PEC.</p>

<h2>Art. 17 – Foro competente</h2>
<p>Qualunque controversia dovesse insorgere tra il Cliente e Kosmos in dipendenza diretta o indiretta del presente contratto sarà di competenza esclusiva del Foro di Macerata, con l'espressa esclusione di qualsivoglia altro Foro potesse essere competente.</p>

<hr>
<p>Ai sensi e per gli effetti degli artt. 1341 e 1342 Cod. Civ., il Cliente dichiara di approvare le seguenti clausole: Art. 3 (Corrispettivi); Art. 4 (Responsabilità del Cliente); Art. 7 (Procedure di acquisto e di vendita); Art. 8 (Limite di spesa); Art. 9 (Compensazione); Art. 11 (Durata del contratto); Art. 12 (Risoluzione anticipata); Art. 13 (Effetti del recesso e dell'estinzione); Art. 15 (Clausola arbitrale); Art. 16 (Elezione di domicilio); Art. 17 (Foro competente).</p>
HTML;
    }

    // ── Branding ──────────────────────────────────────────────────────────────

    public static function branding(): self
    {
        return static::query()->firstOrCreate(
            ['code' => 'branding'],
            [
                'circuit_name'    => 'KMoney',
                'circuit_tagline' => 'La moneta complementare del Gruppo Kosmos',
                'contact_email'   => 'info@kosmomoney.com',
                'primary_color'   => '#3d5566',
                'accent_color'    => '#0f766e',
            ]
        );
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : null;
    }

    // ── User limit defaults ────────────────────────────────────────────────────

    public static function userLimitDefaults(): self
    {
        return static::query()->firstOrCreate(
            ['code' => 'user_limit_defaults'],
            [
                'default_circuit_capacity_limit'    => null,
                'default_negative_balance_limit'    => null,
                'default_daily_transaction_limit'   => null,
                'default_monthly_transaction_limit' => null,
                'default_per_movement_limit'        => 200000, // 2.000 KY — hard fallback sicuro
                'payment_confirm_totp_threshold'    => null,
                'payment_pin_threshold'             => null,
                'welcome_bonus_amount'              => 0, // centesimi; 0 = disabilitato
            ]
        );
    }

    public function defaultsMap(): array
    {
        return [
            'circuit_capacity_limit'         => $this->default_circuit_capacity_limit,
            'negative_balance_limit'         => $this->default_negative_balance_limit,
            'daily_transaction_limit'        => $this->default_daily_transaction_limit,
            'monthly_transaction_limit'      => $this->default_monthly_transaction_limit,
            'per_movement_limit'             => $this->default_per_movement_limit,
            'payment_confirm_totp_threshold' => $this->payment_confirm_totp_threshold,
            'payment_pin_threshold'          => $this->payment_pin_threshold,
        ];
    }
}
