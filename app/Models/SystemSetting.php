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
 * @property int $referral_bonus_amico_amount
 * @property int $referral_bonus_agente_amount
 * @property int $referral_bonus_attivita_amount
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
        'referral_bonus_amico_amount',
        'referral_bonus_agente_amount',
        'referral_bonus_attivita_amount',
        'contract_force_sign',
        'contract_required_from',
        'contract_text',
        'contract_version',
        'mlm_agent_contract_text',
        'mlm_agent_contract_version',
        'mlm_points_validity_override_minutes',
        'mlm_knm_margin_percent',
        'mlm_root_agent_id',
        'mlm_payout_threshold_eur_cents',
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
<p>a) I Clienti negozieranno liberamente tra di loro ed in assoluta autonomia i termini di ciascuna fornitura; b) raggiunto l'accordo relativo ad una fornitura ed alla percentuale di compensazione che potrà essere pari a 0%, 25%, 50%, 75%, 100%, al fine di rendere possibile il pagamento in compensazione, l'acquirente dovrà accedere alla apposita sezione del Portale o dell'App e compilare il modulo di pagamento con il nominativo del venditore, del corrispettivo totale della compravendita e dell'importo da pagarsi in Kmoney attraverso il Portale. Dopo aver inserito i dati indicati, dovrà confermare l'operazione selezionando il tasto di conferma e inserendo le proprie credenziali di accesso (email e password scelte in fase di registrazione); c) Kosmos invierà ad acquirente e fornitore un messaggio riepilogativo della transazione, con indicazione del valore totale della fornitura e dell'importo in Kmoney; d) la ricezione dell'importo da parte del venditore determinerà la conclusione della transazione; e) venditore e acquirente riceveranno notifica dell'avvenuta transazione; f) ferme le procedure di acquisto e di vendita di cui al precedente punto 7.a., il venditore potrà richiedere che il corrispettivo di ciascuna vendita venga regolato dall'acquirente per la parte prevista in Kmoney attraverso il Portale, mentre per la rimanente parte venga saldato con i termini e le modalità pattuite di concerto con l'acquirente; g) qualora il venditore reputi conveniente evitare di pattuire con l'acquirente di volta in volta per ciascuna vendita la porzione di pagamento richiesta in Kmoney, potrà predefinire in percentuale fissa tale ammontare. In tal caso la percentuale in Kmoney proposta dal venditore sarà pubblicata all'interno dell'area riservata del Portale e nell'App; h) la percentuale in Kmoney definita ai sensi del punto precedente sarà applicata su ciascuna vendita effettuata dal Cliente sino ad eventuale modifica. Tale modifica avrà efficacia immediatamente. Non è previsto alcun limite al numero di variazioni che è possibile richiedere in vigenza di adesione; i) completata la procedura di acquisto e di vendita ed accreditato il relativo ammontare di Kmoney nella posizione contabile del Cliente che ha effettuato la vendita, questi rinuncia espressamente a qualunque azione diretta nei confronti dell'acquirente per ottenere il pagamento dell'importo accreditatogli in Kmoney; l) per ciascuna fornitura, il fornitore emetterà regolare documento fiscale nei confronti dell'acquirente.</p>

<h2>Art. 8 – Limite di spesa</h2>
<p>Al fine di consentire al Cliente la possibilità di effettuare acquisti, anche prima di aver effettuato vendite, Kosmos potrà concedere al Cliente stesso la possibilità di effettuare acquisti attraverso KSM anche senza avere nella propria Posizione Contabile la disponibilità di Kmoney derivanti da precedenti vendite. Tale possibilità sarà compresa entro un dato limite di spesa che Kosmos si riserva di concedere a ciascun Cliente. La capacità di acquisto in compensazione di ciascun Cliente all'interno del Circuito è data da tale importo a cui va di volta in volta aggiunto il valore totale delle vendite e detratto il valore totale degli acquisti effettuati in compensazione. La concessione di un credito è subordinata all'esito positivo dell'istruttoria che Kosmos eseguirà circa l'affidabilità del Cliente e pertanto resta inteso che Kosmos potrà sempre accettare la sottoscrizione dell'adesione da parte del Cliente anche assegnandogli un limite di spesa pari a zero Kmoney. In tal caso il Cliente potrà effettuare operazioni in acquisto solo se dal suo Estratto Conto risulti un saldo contabile positivo. Il limite di spesa concesso potrà sempre essere modificato o revocato qualora Kosmos ritenga, a suo insindacabile giudizio, cambiati o venuti meno i requisiti riscontrati al momento della sua eventuale concessione.</p>

<h2>Art. 9 – Compensazione</h2>
<p>Qualora a seguito dell'utilizzo del limite di spesa eventualmente concesso, l'Estratto Conto del Cliente evidenzi un saldo contabile negativo, il Cliente stesso sarà tenuto ad eseguire nei confronti di altri Clienti che ne facciano richiesta e di volta in volta indicati da Kosmos una o più vendite in compensazione 100%, fino al pareggio della propria posizione contabile. Nonostante quanto previsto al paragrafo che precede, qualora l'Estratto Conto del Cliente evidenzi un saldo contabile negativo, il Cliente dovrà pareggiare immediatamente la propria posizione contabile, versando in denaro un importo equivalente al proprio debito di Kmoney direttamente a Kosmos nei seguenti casi: il Cliente si rifiuti di eseguire le vendite all'interno del Circuito come richieste da Kosmos; il Cliente non compensi, per qualsivoglia motivo, un debito per acquisti effettuati nel termine di 12 mesi a decorrere dalla conclusione dell'operazione; gli effetti del contratto di adesione al Circuito di Kosmos vengano meno per disdetta, recesso o per qualsivoglia altra causa; il contratto venga disdetto dal Cliente, ovvero disattivato ad opera di Kosmos per il mancato pagamento dei corrispettivi di cui all'articolo 3. Una volta ricevuto il pagamento Kosmos provvederà ad annotare il relativo versamento nell'Estratto Conto del Cliente liberandolo. A fronte delle somme ricevute ai sensi del capoverso precedente, nei limiti dell'importo effettivamente ricevuto, Kosmos provvederà ad immettere nel Circuito prodotti e servizi per un valore equivalente.</p>

<h2>Art. 10 – Accettazione del Contratto</h2>
<p>Il rapporto tra Kosmos e il Cliente è regolato dalle presenti condizioni generali di contratto, nonché dalla richiesta di adesione debitamente compilata dal richiedente ed inviata a Kosmos, anche in modalità telematica. Kosmos accetterà le richieste di adesione pervenute a sua sola ed insindacabile discrezione, comunicando al richiedente l'eventuale non accettazione a mezzo PEC o altro strumento equivalente. Ogni richiedente si registra autonomamente sul sito del Circuito scegliendo l'indirizzo email e la password che utilizzerà per l'accesso alla propria area riservata.</p>

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

    // ── Contratto di nomina Agente KNM ───────────────────────────────────────

    /**
     * Il contratto agente vive sulla stessa riga 'contract' delle impostazioni
     * di adesione, in colonne dedicate (mlm_agent_contract_text/version).
     */
    public static function agentContractSettings(): self
    {
        return static::contractSettings();
    }

    /**
     * Restituisce il testo del contratto agente con i placeholder sostituiti.
     *
     * 2026-07-31 (richiesta di Laura): quando un agente ne registra uno nuovo
     * sotto di sé (vedi MlmPortalController::registraAgenteStore()), il
     * contratto deve arrivare al nuovo agente già COMPILATO con i suoi dati
     * anagrafici e quelli dello sponsor — non solo nome/email come prima.
     *
     * Placeholder disponibili:
     *   [[nome_agente]], [[email_agente]], [[telefono_agente]],
     *   [[codice_fiscale_agente]], [[data_nascita_agente]], [[luogo_nascita_agente]],
     *   [[indirizzo_residenza_agente]], [[cap_residenza_agente]],
     *   [[comune_residenza_agente]], [[provincia_residenza_agente]],
     *   [[nome_sponsor]], [[codice_agente_sponsor]], [[data_firma]].
     */
    public function renderAgentContractText(User $user): string
    {
        $text = $this->mlm_agent_contract_text ?? self::defaultAgentContractText();

        $sponsor = $user->referredBy;

        $map = [
            '[[nome_agente]]'               => e($user->name ?? ''),
            '[[email_agente]]'              => e($user->email ?? ''),
            '[[telefono_agente]]'           => e($user->phone ?? ''),
            '[[codice_fiscale_agente]]'     => e($user->fiscal_code ?? ''),
            '[[data_nascita_agente]]'       => $user->birth_date ? $user->birth_date->format('d/m/Y') : '',
            '[[luogo_nascita_agente]]'      => e($user->birth_place ?? ''),
            '[[indirizzo_residenza_agente]]' => e($user->residence_address ?? ''),
            '[[cap_residenza_agente]]'      => e($user->residence_zip ?? ''),
            '[[comune_residenza_agente]]'   => e($user->residence_city ?? ''),
            '[[provincia_residenza_agente]]' => e($user->residence_province ?? ''),
            // Lo sponsor è sempre un agente (assegnato in registraAgenteStore()
            // o risolto dalla richiesta classica): niente chiamata ad
            // agentCode() qui per evitare di assegnarne uno per effetto
            // collaterale in fase di sola lettura — mostriamo il codice solo
            // se già presente.
            '[[nome_sponsor]]'              => e($sponsor?->name ?? ''),
            '[[codice_agente_sponsor]]'     => e($sponsor?->mlm_agent_code ?? ''),
            '[[data_firma]]'                => now()->format('d/m/Y'),
        ];

        return str_replace(array_keys($map), array_values($map), $text);
    }

    public static function defaultAgentContractText(): string
    {
        return <<<'HTML'
<p><strong>KOSMOS S.r.l. — Modulo di adesione e Condizioni Generali per l'Incaricato di Vendita</strong></p>
<p>Ai sensi dell'art. 19 D. Lgs. 114/1998. Il presente modulo, compilato con i dati anagrafici del candidato Incaricato al momento della sua nomina da parte dello sponsor e sottoscritto digitalmente con codice OTP inviato all'indirizzo email registrato, costituisce a tutti gli effetti richiesta di nomina ad Incaricato di Vendita Kosmos e adesione integrale alle condizioni generali che seguono.</p>

<h2>Dati del candidato Incaricato</h2>
<table>
<tr><td><strong>Nome e cognome</strong></td><td>[[nome_agente]]</td></tr>
<tr><td><strong>Codice fiscale</strong></td><td>[[codice_fiscale_agente]]</td></tr>
<tr><td><strong>Data e luogo di nascita</strong></td><td>[[data_nascita_agente]] — [[luogo_nascita_agente]]</td></tr>
<tr><td><strong>Residenza</strong></td><td>[[indirizzo_residenza_agente]], [[cap_residenza_agente]] [[comune_residenza_agente]] ([[provincia_residenza_agente]])</td></tr>
<tr><td><strong>Email</strong></td><td>[[email_agente]]</td></tr>
<tr><td><strong>Telefono</strong></td><td>[[telefono_agente]]</td></tr>
<tr><td><strong>Sponsor</strong></td><td>[[nome_sponsor]] [[codice_agente_sponsor]]</td></tr>
<tr><td><strong>Data compilazione</strong></td><td>[[data_firma]]</td></tr>
</table>

<hr>

<p><strong>CONDIZIONI GENERALI PER L'INCARICATO DI VENDITA</strong></p>

<p><strong>1.</strong> L'incaricato di vendita Kosmos (in seguito indicato come incaricato) opera ai sensi dell'art.19 dl D. Lgs.114/1998 promuovendo la vendita di servizi della Kosmos s.r.l. (in seguito indicata come Kosmos) raccogliendo proposte d'ordine dai consumatori finali su moduli predisposti da Kosmos. Solo l'incaricato di vendita autorizzato da Kosmos può promuovere i servizi di Kosmos. L'incaricato può promuovere i servizi di Kosmos solo a consumatori finali.</p>

<p><strong>2.</strong> L'incaricato è un lavoratore autonomo, è responsabile della propria attività e non sarà considerato a nessun fine o titolo dipendente, socio, rappresentante, agente o mandatario di Kosmos o di qualsiasi terzo con cui Kosmos tratti o abbia rapporti d'affari. L'incaricato non dovrà presentarsi in alcun modo come dipendente, socio, rappresentante, agente o mandatario di Kosmos o di qualsiasi terzo con cui Kosmos tratti o abbia rapporti d'affari. L'incaricato non può causare od incorrere in alcuna responsabilità a nome di Kosmos. Kosmos non è responsabile per accordi conclusi o promesse, dichiarazioni o proposte che si assumono effettuate dall'incaricato a nome di Kosmos.</p>

<p><strong>3.</strong> Al fine di diventare incaricato, il candidato deve compilare l'apposito modulo di adesione e la sua candidatura deve essere sempre accettata da Kosmos. Kosmos deve ricevere l'originale del modulo debitamente compilato che deve essere sottoscritto dal candidato e dal proprio sponsor personalmente, senza cancellature, modifiche o segni di alcun tipo. La richiesta di nomina a incaricato è affidata all'insindacabile giudizio di Kosmos. Ad accettazione avvenuta Kosmos provvederà a consegnare all'incaricato il tesserino di riconoscimento rilasciato ai sensi del D. Lgs.114/98. L'incaricato è personalmente responsabile di tale documento che dovrà essere esibito durante lo svolgimento dell'attività e prontamente restituito quando dovesse cessare di operare per qualsiasi motivo per Kosmos.</p>

<p><strong>4.</strong> Il rimborso spese forfettario annuale per assistenza e consulenza deve essere pagato a Kosmos entro la data del primo anniversario dalla data di stipula del presente contratto ed in seguito annualmente. E' previsto un ulteriore rimborso spese separato di €. 2,25 per ogni versamento necessario per procedere ai pagamenti in favore degli incaricati. L'incaricato dà atto che tale rimborso potrà essere dedotto dalle provvigioni a lui dovute in qualunque momento. Ad ogni rinnovo annuale del presente contratto l'incaricato dovrà rinnovare, in conformità alle condizioni e termini di cui al presente contratto che saranno in vigore, anche la propria adesione alla Direttive e Procedure Kosmos ed al piano dei compensi di vendita.</p>

<p><strong>5.</strong> Tutte le spese in cui l'incaricato incorrerà nell'esercizio della sua attività sono esclusivamente a suo carico.</p>

<p><strong>6.</strong> L'età minima richiesta per diventare incaricato di vendita è 18 anni; l'incaricato dichiara di essere residente in Italia.</p>

<p><strong>7.</strong> Qualora l'incaricato svolga la propria attività per professione abituale, ancorché non esclusiva, l'incaricato di vendita si impegna a comunicare per iscritto a Kosmos la propria partita Iva ed a fornire a Kosmos copia del certificato di attribuzione della stessa. Kosmos si riserva il diritto di accettare che l'incaricato svolga la sua attività per professione abituale, ancorché non esclusiva, e qualora Kosmos ritenga sussistere tale requisito, anche indipendentemente dalla comunicazione di cui sopra, l'incaricato sarà tenuto a fornire a Kosmos, su richiesta di quest'ultima, copia del certificato di attribuzione della partita Iva. Qualora si verifichi tale eventualità Kosmos si riserva il diritto di sospendere ogni pagamento dovuto all'incaricato fino ad avvenuto ricevimento del certificato di attribuzione della partita Iva. Se la registrazione della partita Iva viene meno per qualsiasi motivo, l'incaricato dovrà comunicarlo per iscritto a Kosmos entro 14 giorni dalla data in cui la registrazione è venuta meno. Qualora Kosmos sia tenuta a pagare l'Iva su qualsiasi pagamento dovuto all'incaricato ai sensi del piano dei compensi Kosmos, qualora Kosmos ritenga opportuno, a qualunque titolo e previa approvazione, autofatturarsi tali somme, l'Iva sarà pagata all'incaricato soltanto se questi avrà fornito alla Kosmos copia del certificato di attribuzione della partita Iva. Qualora Kosmos sia soggetta ad effettuare qualsiasi pagamento di Iva alle autorità competenti, in conseguenza dell'adempimento dell'incaricato a comunicare a Kosmos l'inizio e/o la cessazione dell'esercizio dell'attività dell'incaricato per professione abituale, ancorché non esclusiva, ovvero il venir meno della partita Iva, Kosmos avrà il diritto di recuperare l'importo corrispondente mediante deduzione dell'importo medesimo dal conto dell'incaricato o mediante qualsiasi altro mezzo di volta in volta disponibile.</p>

<p><strong>8.</strong> Il presente contratto potrà essere risolto in qualsiasi momento dall'incaricato Kosmos, con o senza motivazione, in conformità a quanto previsto dalle Direttive e Procedure Kosmos, previa comunicazione scritta da inviare a Kosmos con preavviso di 14 gg. all'indirizzo indicato sull'intestazione del presente modulo di adesione. Il presente contratto potrà altresì essere risolto da Kosmos a sua propria totale discrezione, ai sensi delle Direttive e Procedure Kosmos, previa comunicazione scritta da inviare all'indirizzo dell'incaricato indicato sul modulo di adesione con preavviso di 14 giorni. Nel caso in cui una delle parti risolva il presente contratto l'incaricato dovrà restituire a Kosmos ogni bene (ivi inclusi il materiale promozionale formativo ed i manuali).</p>

<p><strong>9.</strong> In caso di risoluzione del presente contratto, per qualsiasi ragione o causa, l'incaricato avrà diritto di essere esonerato da ogni futura responsabilità contrattuale nei confronti di Kosmos in relazione al sistema commerciale di cui al presente contratto ad eccezione di: a) responsabilità relative a pagamenti effettuati in favore dell'incaricato in forza di eventuali contratti stipulati dall'incaricato in qualità di agente della Kosmos; b) qualsiasi obbligo di pagare a Kosmos il prezzo di beni o servizi già forniti all'incaricato da Kosmos nel caso in cui l'incaricato non abbia restituito tali beni a Kosmos ai sensi delle norme di cui sopra delle Direttive e Procedure Kosmos.</p>

<p><strong>10.</strong> L'incaricato potrà acquisire tutti i clienti che desidera, purché si tratti di consumatori finali. Per ogni cliente personalmente acquisito che avrà stipulato un contratto con Kosmos, l'incaricato riceverà una provvigione basata sui "clienti personali" dell'incaricato, ai sensi del piano dei compensi. La corresponsione di qualsiasi altra somma spettante all'incaricato sarà regolata dal piano dei compensi. Kosmos non corrisponderà provvigioni o bonus di alcun tipo per la sponsorizzazione di nuovi incaricati di vendita. Kosmos corrisponderà provvigioni o bonus solo fino e non oltre la data di risoluzione del presente contratto. Kosmos pagherà provvigioni o bonus solo in seguito alla avvenuta promozione dei servizi Kosmos ai consumatori finali, secondo le condizioni e i termini di cui al piano provvigionale. L'incaricato è tenuto, quando invitato, a partecipare alle riunioni della Kosmos ed ai corsi di formazione dalla Kosmos erogati. L'incaricato concentrerà, come condizione necessaria per il ricevimento di provvigioni, la propria attività di promozione di servizi nei confronti di quei clienti che non partecipano al piano commerciale di Kosmos. Kosmos potrà modificare le aliquote del piano dei compensi per i prodotti promozionali o i prezzi concordati.</p>

<p><strong>11.</strong> L'incaricato deve dare ad ogni cliente che effettua un ordine un modulo d'ordine debitamente compilato che deve essere sottoscritto dal cliente stesso. L'incaricato dovrà trasmettere gli ordini raccolti a Kosmos per l'accettazione ed esecuzione degli stessi da parte della Kosmos medesima. L'incaricato non sottoporrà a Kosmos alcun modulo d'ordine che non sia sottoscritto dal cliente. Nessun ordine da parte dell'incaricato relativo ai servizi Kosmos avrà effetto a meno che e finché non sia stato accettato da Kosmos.</p>

<p><strong>12.</strong> L'incaricato non può avere diritti diversi da quelli derivanti dal proprio rapporto con Kosmos.</p>

<p><strong>13.</strong> L'incaricato, ai sensi e per gli effetti di cui al decreto legislativo n°114/98, prende atto che non possono esercitare l'attività di incaricato di vendita, salvo che abbiano ottenuto la riabilitazione:</p>
<ol type="a">
<li>coloro che sono stati dichiarati falliti;</li>
<li>coloro che hanno riportato una condanna, con sentenza passata in giudicato per delitto non colposo, per il quale è prevista una pena detentiva non inferiore nel minimo a tre anni, sempre che sia stata applicata, in concreto, una pena superiore al minimo edittale;</li>
<li>coloro che hanno riportato una condanna a pena detentiva, accertata con sentenza passato in giudicato per uno dei delitti di cui al titolo II e VIII del libro II del codice penale, ovvero per uno dei seguenti reati: ricettazione, riciclaggio, emissione di assegni a vuoto, insolvenza fraudolenta, bancarotta fraudolenta, usura, sequestro di persona a scopo di estorsione, rapina;</li>
<li>coloro che hanno riportato due o più condanne a pena detentiva o a pena pecuniaria, nel quinquennio precedente all'inizio dell'esercizio dell'attività, accertate con sentenza passato in giudicato, per uno dei delitti previsti dagli art. 442, 444, 513, 513 bis, 515, 516 e 517 del codice penale o per delitti di frode nella preparazione o nel commercio degli alimenti, previsti da leggi speciali;</li>
<li>coloro che sono sottoposti ad una delle misure di prevenzione di cui alla Legge del 27 dicembre 1956 n° 1423 nei cui confronti si stata applicata una delle misure previste della legge 31 maggio 1965 n° 575, ovvero siano stati dichiarati delinquenti abituali professionali o per tendenza.</li>
</ol>

<p><strong>14.</strong> L'incaricato terrà una documentazione accurata della propria attività e non porrà in essere alcuna attività fuorviante, ingannevole o contraria all'etica. L'incaricato si atterrà alle leggi che regolano la promozione dei servizi commercializzati da Kosmos, ivi inclusi a titolo esemplificativo, ogni eventuale permesso, autorizzazione o licenza richieste per svolgere l'attività di incaricato ai sensi del presente contratto. Kosmos fornirà all'incaricato un'adeguata documentazione, anche in forma di moduli d'ordine dettagliati, di fatture o di ricevute, in relazione a tutti i beni ed i servizi forniti da Kosmos all'incaricato, per i quali l'incaricato dovrà effettuare un pagamento.</p>

<p><strong>15.</strong> In nessun caso Kosmos sarà responsabile per qualsiasi danno o perdita che possa derivare da ogni causa, ivi incluse, a titolo esemplificativo, la violazione di garanzie, ritardi, atti, errori o omissioni di Kosmos e/o dei suoi fornitori di beni e servizi, o nel caso di interruzione o modifica di un prodotto o servizio da parte di Kosmos dei suoi fornitori di beni e servizi, salvo che tale danno o perdita o l'interruzione o la modifica di un prodotto o servizio siano dovuti a dolo o colpa grave di Kosmos e/o dei suoi fornitori di beni e servizi, e salvo in ogni caso quanto previsto dal Decreto del Presidente della Repubblica n° 224 del 24 maggio 1988 in materia di responsabilità per danno da prodotti difettosi.</p>

<p><strong>16.</strong> L'incaricato è libero di scegliere i propri mezzi, metodi e modalità operative e di scegliere gli orari ed i luoghi in cui svolgerà la propria attività in conformità alle indicazioni e suggerimenti a lui dati da Kosmos e sulla base del presente contratto e delle Direttive e Procedure Kosmos.</p>

<p><strong>17.</strong> L'incaricato dà atto che non gli è stato garantito alcun provento né assicurato alcun profitto o garanzia di successo in relazione alla sua attività e che non ha ricevuto alcuna assicurazione da Kosmos o dal suo sponsor in relazione a profitti garantiti e che non gli è stata prospettata alcuna aspettativa di guadagno derivante dalla sua attività di incaricato.</p>

<p><strong>18.</strong> L'incaricato accetta di manlevare completamente e pienamente Kosmos e i suoi fornitori di beni e servizi da ogni costo o danno derivanti da una o più violazioni del presente contratto. Al fine di recuperare i danni o le spese sostenute a seguito di tali violazioni, Kosmos potrà dedurre tali importi dalle provvigioni e/o dagli altri pagamenti dovuti all'incaricato.</p>

<p><strong>19.</strong> L'incaricato dà atto che Kosmos si riserva espressamente tutti i diritti di proprietà intellettuale relativi al nome della società, al logo, ai marchi, ai marchi di servizio e ai materiali coperti dai diritti d'autore ("Proprietà Intellettuale"). All'Incaricato viene concesso il diritto non esclusivo, per tutta la durata del presente contratto, di utilizzare la proprietà intellettuale di Kosmos in stretta conformità alle Direttive e Procedure Kosmos. L'incaricato non utilizzerà la proprietà intellettuale di Kosmos se non nei modi autorizzati per iscritto da Kosmos o previsti nel materiale pubblicitario e promozionale fornito, creato o pubblicato da Kosmos L'incaricato non potrà fotocopiare o duplicare il materiale fornito o acquistato da Kosmos se non previa autorizzazione scritta da parte di Kosmos: l'utilizzo non autorizzato dalla proprietà intellettuale sarà considerato illegittimo e costituirà causa di risoluzione del presente contratto da parte di Kosmos.</p>

<p><strong>20.</strong> Nello svolgimento della propria attività, l'incaricato farà uso esclusivamente di materiale stampato prodotto da Kosmos. L'incaricato non potrà fare uso di alcuna dichiarazione, affermazione, rappresentazione o assicurazione che non siano riprodotte sul materiale pubblicitario di Kosmos, sia nel promuovere la vendita di prodotti o servizi sia nel reclutare nuovi incaricati. Qualsiasi ulteriore materiale usato a scopi promozionali potrà essere utilizzato solo in circostanze straordinarie e dovrà essere preventivamente autorizzato per iscritto da Kosmos. L'incaricato non potrà, se non previa autorizzazione o su richiesta di Kosmos, rivelare ad alcuna persona i segreti commerciali e le informazioni riservate acquisite nel corso della durata del presente contratto, in conformità alle Direttive e Procedure Kosmos.</p>

<p><strong>21.</strong> Nel corso della durata del presente contratto l'incaricato non potrà, direttamente o indirettamente, stornare, attirare, distogliere, invitare i clienti di Kosmos o i suoi fornitori di prodotti e servizi a svolgere per suo conto l'attività di promozione, indipendentemente dal fatto che tali clienti siano stati originariamente acquisiti da Kosmos tramite l'incaricato, in conformità alle Direttive e Procedure Kosmos. Inoltre, nel corso della durata del presente contratto, l'incaricato non potrà stipulare alcun accordo commerciale con qualsiasi fornitore di prodotti e servizi di Kosmos. Nel corso della durata del presente contratto, l'incaricato non dovrà proporre ad alcun incaricato, sia attivo che inattivo, di partecipare ad una rete di vendita organizzata da qualsiasi altra società, indipendentemente dal fatto che tale società offra servizi e prodotti analoghi. La violazione della presente clausola potrà costituire giusta causa di risoluzione del presente contratto da parte di Kosmos e della perdita di ogni diritto di promozione, ivi inclusi tutti i compensi correnti e futuri, i bonus ed i pagamenti di qualsiasi tipo.</p>

<p><strong>22.</strong> Il presente contratto è regolato e dovrà essere interpretato ai sensi della legge italiana; per qualsiasi controversia tra le parti nascente dal presente contratto o da ogni altro rapporto contrattuale tra le parti sarà esclusivamente competente l'Autorità Giudiziaria di Macerata.</p>

<p><strong>23.</strong> L'incaricato dà atto che il presente contratto non potrà essere modificato né integrato se non per iscritto e con la sottoscrizione Kosmos. L'incaricato non potrà cedere o trasferire in alcun modo i diritti derivanti dalla sua posizione di incaricato senza preventiva autorizzazione scritta di Kosmos.</p>

<p><strong>24.</strong> L'inerzia di ciascuna parte in qualsiasi momento a pretendere dall'altra parte l'adempimento delle condizioni del presente contratto non pregiudicherà in alcun modo il diritto di tale parte a pretendere che l'altra parte rimedi a una violazione di alcuna delle condizioni del presente contratto e non costituirà rinuncia a contestare perduranti e successive violazioni di tali condizioni.</p>

<p><strong>25.</strong> L'incaricato dà atto di essere stato informato da Kosmos che ogni informazione che lo stesso fornirà a Kosmos sarà conservata da Kosmos in un database elettronico e verrà utilizzata da Kosmos per scopi commerciali o amministrativi. L'incaricato dà altresì atto che Kosmos potrà rivelare tali informazioni con riferimento a tali scopi, ad altri membri delle società del gruppo Kosmos sia che si trovino all'interno sia che si trovino all'esterno della UE e ad altre persone e, in particolare, potrà rivelare tali dati ad altri incaricati in quanto facenti parte dell'organizzazione di Kosmos. L'incaricato si impegna a informare per iscritto e senza ritardo Kosmos su qualsiasi cambiamento riguardante i propri dati personali comunicati a Kosmos. L'incaricato autorizza Kosmos a conservare, trattenere e rivelare tali informazioni alle condizioni sopra indicate.</p>

<hr>
<p>Con la firma digitale tramite codice OTP inviato all'indirizzo email registrato in data [[data_firma]], il candidato Incaricato sopra generalizzato dichiara di aver letto, compreso e accettato integralmente il presente modulo di adesione e le Condizioni Generali per l'Incaricato di Vendita che precedono, in sostituzione della sottoscrizione autografa e del timbro/firma.</p>
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

    // ── MLM settings (2026-07-13) ───────────────────────────────────────────────

    /**
     * Impostazioni MLM globali configurabili da admin: l'override "da test"
     * della scadenza punti (mlm_points_validity_override_minutes, usato da
     * MlmPointsService) e l'agente radice unico del sistema
     * (mlm_root_agent_id, vedi MlmTreeService::systemRootAgent()) — il posto
     * dove aggiungere futuri interruttori globali MLM.
     */
    public static function mlmSettings(): self
    {
        return static::query()->firstOrCreate(
            ['code' => 'mlm'],
            [
                'mlm_points_validity_override_minutes' => null,
                'mlm_knm_margin_percent' => self::MLM_KNM_MARGIN_DEFAULT_PERCENT,
                'mlm_root_agent_id' => null,
                'mlm_payout_threshold_eur_cents' => 0,
            ]
        );
    }

    /**
     * Margine KNM di default ("Prov K", 2026-07-16): le slide "Esempio
     * compensi" usano il 30% nelle tabelle principali (10% in una — e' un
     * parametro, per questo e' configurabile da admin).
     */
    public const MLM_KNM_MARGIN_DEFAULT_PERCENT = 30;

    /**
     * Margine KNM corrente in percento intero (1-100). Le percentuali del
     * reddito residuale MLM (dirette e indirette) si applicano a
     * "Prov K" = importo mensile del cliente x questo margine — mai
     * all'importo pieno (slide "Esempio compensi", confermato da Laura il
     * 2026-07-16: "le slide fanno fede"). NULL su una riga esistente
     * (es. prod gia' creata prima del 2026-07-16) = default 30.
     */
    public function mlmKnmMarginPercent(): int
    {
        return (int) ($this->mlm_knm_margin_percent ?? self::MLM_KNM_MARGIN_DEFAULT_PERCENT);
    }

    /** Agente radice unico del sistema MLM (2026-07-15), se già designato. */
    public function mlmRootAgent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'mlm_root_agent_id');
    }

    /**
     * Soglia minima di prelievo self-service dal portale agente (EUR
     * centesimi, 2026-07-29). NULL sulle righe esistenti pre-migrazione =
     * 0 = nessuna soglia (comportamento pre-esistente: prelevabile qualunque
     * importo > 0). Vedi MlmPayoutService::requestWithdrawal().
     */
    public function mlmPayoutThresholdEurCents(): int
    {
        return max(0, (int) ($this->mlm_payout_threshold_eur_cents ?? 0));
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
                'referral_bonus_amico_amount'       => 1000,  // 10,00 KY
                'referral_bonus_agente_amount'      => 5000,  // 50,00 KY
                'referral_bonus_attivita_amount'    => 10000, // 100,00 KY
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

    /**
     * Importi bonus segnalazione (punto 3 del 27/07) in centesimi KY, per
     * livello. Usato da ReferralBonusService e dal form admin dei default.
     * 0 = livello disabilitato (nessun bonus erogato per quel tipo).
     */
    public function referralBonusAmounts(): array
    {
        return [
            'amico'     => (int) ($this->referral_bonus_amico_amount ?? 0),
            'agente'    => (int) ($this->referral_bonus_agente_amount ?? 0),
            'attivita'  => (int) ($this->referral_bonus_attivita_amount ?? 0),
        ];
    }
}
