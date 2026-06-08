<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
<h2>Art. 1 – Termini e condizioni</h2>
<p>Nel testo del presente contratto si intende indicare con il termine <strong>KMoney</strong> la società che gestisce l'intero Circuito KMoney. <strong>KY</strong>: il simbolo monetario del Circuito KMoney (come &euro; per l'Euro), con valore nominale pari a 1 euro, utilizzato all'interno del Circuito per indicare il valore degli acquisti e delle vendite. <strong>Cliente</strong>: la persona giuridica che richiede di usufruire dei servizi oggetto del presente contratto. <strong>Portale</strong>: il sito e l'applicazione del Circuito.</p>

<h2>Art. 2 – I servizi del Circuito</h2>
<p>KMoney offre al Cliente servizi finalizzati a fornire la possibilità di effettuare acquisti o vendite di beni e servizi da e ad altri Clienti tramite scambi multilaterali in compensazione, e a tal fine: a) mette a disposizione un Portale con le aziende suddivise per categoria; b) inserisce ogni Cliente nel Portale rendendo visibile l'adesione; c) mette a disposizione uno spazio vetrina virtuale con beni e servizi offerti; d) redige l'estratto conto e aggiorna il saldo contabile di ciascun Cliente; e) notifica le richieste di acquisto da altri Clienti.</p>

<h2>Art. 3 – Corrispettivi</h2>
<p>Il Cliente riconoscerà a KMoney un canone fisso annuale anticipato e un compenso in percentuale sul venduto così come quantificato nella richiesta di adesione.</p>

<h2>Art. 4 – Responsabilità del Cliente</h2>
<p>Acquisti e vendite tramite KMoney avvengono direttamente tra un Cliente e l'altro; KMoney non è mai acquirente o fornitore. Il Cliente che effettua una vendita si impegna ad eseguirla a regola d'arte. Ciascun Cliente è responsabile degli atti compiuti nell'ambito del Circuito ed esonera espressamente KMoney da ogni responsabilità.</p>

<h2>Art. 5 – Unità di conto</h2>
<p>I KY indicano esclusivamente il valore degli acquisti e delle vendite effettuate tramite KMoney. KMoney non agisce quale istituto di credito; i KY non sono rappresentativi di depositi bancari, di valuta corrente o di titoli. In nessun caso il Cliente potrà chiedere la conversione in valuta corrente delle unità di conto.</p>

<h2>Art. 6 – Estratto Conto</h2>
<p>Le operazioni di acquisto e vendita saranno trascritte nell'Estratto Conto disponibile nell'area riservata del Portale. Trascorsi 15 giorni dall'aggiornamento senza che il Cliente abbia denunciato inesattezze, la posizione contabile sarà considerata accettata.</p>

<h2>Art. 7 – Procedure di acquisto e di vendita</h2>
<p>I Clienti negozieranno liberamente tra di loro. Raggiunto un accordo, l'acquirente accederà alla sezione Portale o App per compilare il modulo di pagamento indicando il venditore, il corrispettivo totale e l'importo in KY. La percentuale di compensazione potrà essere 0%, 25%, 50%, 75% o 100%.</p>

<h2>Art. 8 – Limite di spesa (Fido)</h2>
<p>KMoney potrà concedere al Cliente la possibilità di effettuare acquisti anche senza disponibilità di KY derivanti da precedenti vendite, entro un limite di spesa definito. Tale limite è subordinato all'esito positivo dell'istruttoria e potrà essere modificato o revocato in qualsiasi momento.</p>

<h2>Art. 9 – Compensazione</h2>
<p>Qualora l'Estratto Conto del Cliente evidenzi un saldo negativo, il Cliente sarà tenuto ad eseguire vendite in compensazione 100% fino al pareggio della propria posizione. In caso di mancata compensazione entro 12 mesi o di recesso, il Cliente dovrà versare in denaro un importo equivalente al proprio debito.</p>

<h2>Art. 10 – Accettazione del Contratto</h2>
<p>Il rapporto tra KMoney e il Cliente è regolato dalle presenti condizioni generali, nonché dalla richiesta di adesione compilata dal richiedente. KMoney accetterà le richieste a propria discrezione.</p>

<h2>Art. 11 – Durata del contratto</h2>
<p>Il contratto ha efficacia dalla data di sottoscrizione. Ha durata di 12 mesi e si rinnova automaticamente salvo disdetta inviata 60 giorni prima della scadenza tramite raccomandata A/R o PEC.</p>

<h2>Art. 12 – Risoluzione anticipata</h2>
<p>KMoney ha facoltà di risolvere anticipatamente il contratto senza preavviso in caso di: apertura di procedura concorsuale; cessazione dell'attività; violazione degli artt. 3, 4 o 9; venir meno dei requisiti riscontrati all'atto dell'accettazione; mancata comunicazione di modifiche strutturali.</p>

<h2>Art. 13 – Effetti del recesso</h2>
<p>Alla cessazione del contratto, eventuali KY residui continueranno a sussistere come credito di fornitura; se non utilizzati entro 1 anno si intenderanno rinunciati. In nessun caso il Cliente potrà richiedere la conversione in denaro.</p>

<h2>Art. 14 – Modifiche del contratto</h2>
<p>KMoney ha il diritto di modificare le presenti condizioni in qualunque momento, comunicandolo via PEC. Il Cliente che non intende aderire alle modifiche dovrà comunicare il recesso entro 15 giorni; trascorso tale termine ogni modifica si intenderà accettata.</p>

<h2>Art. 15 – Clausola arbitrale</h2>
<p>Eventuali controversie tra venditore e acquirente saranno regolate tramite arbitro nominato da KMoney. La decisione è vincolante solo se sottoscritta per accettazione da entrambe le parti.</p>

<h2>Art. 16 – Elezione di domicilio</h2>
<p>Il Cliente elegge domicilio presso la sede indicata nel contratto. Le variazioni dovranno essere comunicate tramite raccomandata A/R o PEC per essere opponibili a KMoney.</p>

<h2>Art. 17 – Foro competente</h2>
<p>Qualunque controversia sarà di competenza esclusiva del Foro indicato nella sede legale del Gestore, con esclusione di qualsiasi altro foro.</p>

<hr>
<p><strong>Clausole specialmente approvate ai sensi degli artt. 1341–1342 c.c.:</strong><br>
Art. 3 (Corrispettivi), Art. 4 (Responsabilità del Cliente), Art. 7 (Procedure di acquisto e vendita), Art. 8 (Limite di spesa), Art. 9 (Compensazione), Art. 11 (Durata), Art. 12 (Risoluzione anticipata), Art. 13 (Effetti del recesso), Art. 15 (Clausola arbitrale), Art. 16 (Elezione di domicilio), Art. 17 (Foro competente).</p>
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
                'default_per_movement_limit'        => null,
                'payment_confirm_totp_threshold'    => null,
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
        ];
    }
}
