<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Il periodo di un estratto conto, con le scelte rapide che ha qualsiasi banca
 * (mese corrente, mese scorso, trimestre, anno in corso, anno precedente).
 *
 * PERCHE' UNA CLASSE E NON UN if NEL CONTROLLER (09/09/2026). Lo stesso periodo
 * deve valere per tre formati (PDF, CSV, prima nota) e per tre profili (portale,
 * backoffice admin, scheda cliente del broker): nove combinazioni. Con la
 * risoluzione sparsa nei controller, prima o poi il CSV di "trimestre corrente"
 * copre giorni diversi dal PDF con la stessa etichetta, e il commercialista si
 * ritrova due documenti che non quadrano fra loro. Qui il periodo si risolve una
 * volta sola e i tre formati ricevono lo stesso oggetto.
 *
 * NON LANCIA MAI ECCEZIONI. Un `?periodo=` scritto a mano, un `?mese=13-2026`,
 * un intervallo con le date invertite: sono tutti URL che un utente puo'
 * digitare o un vecchio segnalibro puo' contenere, e un estratto conto che
 * risponde 500 e' peggio di uno che ripiega sul mese scorso. Ogni ingresso
 * sporco ricade sulla scelta rapida piu' vicina.
 *
 * RETROCOMPATIBILITA' `?mese=YYYY-MM`. E' la forma che usavano i link vecchi
 * (scheda azienda in backoffice, email del resoconto mensile, segnalibri degli
 * utenti). Resta valida: senza `periodo`, un `mese` presente vale come
 * `periodo=mese`.
 */
final class StatementPeriod
{
    public const DEFAULT_PRESET = 'mese_precedente';

    /** Tetto di sicurezza per l'intervallo personalizzato: oltre, il PDF diventa ingestibile. */
    private const MAX_GIORNI_PERSONALIZZATO = 1826; // 5 anni esatti

    private const MESI = [
        1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
        'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre',
    ];

    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $label,
        /** mese|trimestre|anno|personalizzato — decide il titolo del documento e il nome del file */
        public readonly string $granularity,
        public readonly string $slug,
    ) {
    }

    // ── Costruttori ─────────────────────────────────────────────────────────

    public static function fromRequest(Request $request, ?CarbonImmutable $now = null): self
    {
        $preset = trim((string) $request->query('periodo', ''));

        if ($preset === '' && trim((string) $request->query('mese', '')) !== '') {
            $preset = 'mese';
        }

        return self::make($preset, [
            'mese'      => trim((string) $request->query('mese', '')),
            'trimestre' => trim((string) $request->query('trimestre', '')),
            'anno'      => trim((string) $request->query('anno', '')),
            'dal'       => trim((string) $request->query('dal', '')),
            'al'        => trim((string) $request->query('al', '')),
        ], $now);
    }

    /**
     * @param array{mese?:string,trimestre?:string,anno?:string,dal?:string,al?:string} $input
     */
    public static function make(string $preset, array $input = [], ?CarbonImmutable $now = null): self
    {
        $now = $now ?? CarbonImmutable::now();

        if (! array_key_exists($preset, self::allPresets())) {
            $preset = self::DEFAULT_PRESET;
        }

        return match ($preset) {
            'mese_corrente'        => self::mese($now, 'mese_corrente'),
            'mese_precedente'      => self::mese($now->subMonth(), 'mese_precedente'),
            'trimestre_corrente'   => self::trimestre($now, 'trimestre_corrente'),
            'trimestre_precedente' => self::trimestre($now->startOfQuarter()->subDay(), 'trimestre_precedente'),
            'anno_corrente'        => self::anno($now, 'anno_corrente'),
            'anno_precedente'      => self::anno($now->subYear(), 'anno_precedente'),
            'ultimi_12_mesi'       => self::ultimi12Mesi($now),
            'mese'                 => self::meseSpecifico($input['mese'] ?? '', $now),
            'trimestre'            => self::trimestreSpecifico($input['trimestre'] ?? '', $now),
            'anno'                 => self::annoSpecifico($input['anno'] ?? '', $now),
            default                => self::personalizzato($input['dal'] ?? '', $input['al'] ?? '', $now),
        };
    }

    // ── Etichette ───────────────────────────────────────────────────────────

    /** Le scelte rapide mostrate come pulsanti, nell'ordine in cui servono davvero. */
    public static function quickPresets(): array
    {
        return [
            'mese_corrente'        => 'Mese corrente',
            'mese_precedente'      => 'Mese scorso',
            'trimestre_corrente'   => 'Trimestre corrente',
            'trimestre_precedente' => 'Trimestre scorso',
            'anno_corrente'        => 'Anno in corso',
            'anno_precedente'      => 'Anno precedente',
            'ultimi_12_mesi'       => 'Ultimi 12 mesi',
        ];
    }

    public static function allPresets(): array
    {
        return self::quickPresets() + [
            'mese'           => 'Mese specifico',
            'trimestre'      => 'Trimestre specifico',
            'anno'           => 'Anno specifico',
            'personalizzato' => 'Intervallo personalizzato',
        ];
    }

    /** "Estratto conto mensile" / "trimestrale" / "annuale" / "per periodo". */
    public function documentTitle(): string
    {
        return match ($this->granularity) {
            'mese'      => 'Estratto conto mensile',
            'trimestre' => 'Estratto conto trimestrale',
            'anno'      => 'Estratto conto annuale',
            default     => 'Estratto conto per periodo',
        };
    }

    /** Suffisso del nome file: estratto-conto-{azienda}-{questo}.pdf */
    public function fileSlug(): string
    {
        return $this->slug;
    }

    /** Copre piu' di un mese solare? Decide se il PDF stampa il riepilogo per mese. */
    public function spansMultipleMonths(): bool
    {
        return $this->start->format('Y-m') !== $this->end->format('Y-m');
    }

    public function giorni(): int
    {
        return (int) round($this->start->startOfDay()->diffInDays($this->end->startOfDay())) + 1;
    }

    /** I parametri da rimettere in un link o in un form perche' il periodo si conservi. */
    public function queryParams(): array
    {
        return match ($this->preset) {
            'mese'           => ['periodo' => 'mese', 'mese' => $this->start->format('Y-m')],
            'trimestre'      => ['periodo' => 'trimestre', 'trimestre' => $this->start->format('Y') . '-T' . $this->start->quarter],
            'anno'           => ['periodo' => 'anno', 'anno' => $this->start->format('Y')],
            'personalizzato' => ['periodo' => 'personalizzato', 'dal' => $this->start->format('Y-m-d'), 'al' => $this->end->format('Y-m-d')],
            default          => ['periodo' => $this->preset],
        };
    }

    // ── Fabbriche interne ───────────────────────────────────────────────────

    private static function mese(CarbonImmutable $ref, string $preset): self
    {
        $start = $ref->startOfMonth();

        return new self(
            $preset,
            $start,
            $ref->endOfMonth(),
            self::ucfirst(self::MESI[(int) $start->format('n')]) . ' ' . $start->format('Y'),
            'mese',
            $start->format('Y-m'),
        );
    }

    private static function trimestre(CarbonImmutable $ref, string $preset): self
    {
        $start = $ref->startOfQuarter();
        $end   = $ref->endOfQuarter();
        $q     = (int) $start->quarter;

        return new self(
            $preset,
            $start,
            $end,
            $q . '° trimestre ' . $start->format('Y')
                . ' (' . self::MESI[(int) $start->format('n')] . ' – ' . self::MESI[(int) $end->format('n')] . ')',
            'trimestre',
            $start->format('Y') . '-T' . $q,
        );
    }

    private static function anno(CarbonImmutable $ref, string $preset): self
    {
        $start = $ref->startOfYear();

        return new self(
            $preset,
            $start,
            $ref->endOfYear(),
            'Anno ' . $start->format('Y'),
            'anno',
            $start->format('Y'),
        );
    }

    private static function ultimi12Mesi(CarbonImmutable $now): self
    {
        $end   = $now->endOfMonth();
        $start = $now->subMonths(11)->startOfMonth();

        return new self(
            'ultimi_12_mesi',
            $start,
            $end,
            'Ultimi 12 mesi (' . self::MESI[(int) $start->format('n')] . ' ' . $start->format('Y')
                . ' – ' . self::MESI[(int) $end->format('n')] . ' ' . $end->format('Y') . ')',
            'personalizzato',
            $start->format('Ym') . '-' . $end->format('Ym'),
        );
    }

    private static function meseSpecifico(string $value, CarbonImmutable $now): self
    {
        $ref = self::parse('Y-m', $value);

        if (! $ref) {
            return self::mese($now->subMonth(), self::DEFAULT_PRESET);
        }

        $periodo = self::mese($ref, 'mese');

        return new self('mese', $periodo->start, $periodo->end, $periodo->label, 'mese', $periodo->slug);
    }

    /** Formato atteso: "2026-T3" (anche "2026-3" e "2026-t3" passano). */
    private static function trimestreSpecifico(string $value, CarbonImmutable $now): self
    {
        if (! preg_match('/^(\d{4})-[Tt]?([1-4])$/', $value, $m)) {
            return self::trimestre($now, 'trimestre_corrente');
        }

        $ref = CarbonImmutable::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1);

        if (! $ref) {
            return self::trimestre($now, 'trimestre_corrente');
        }

        $periodo = self::trimestre($ref, 'trimestre');

        return new self('trimestre', $periodo->start, $periodo->end, $periodo->label, 'trimestre', $periodo->slug);
    }

    private static function annoSpecifico(string $value, CarbonImmutable $now): self
    {
        if (! preg_match('/^\d{4}$/', $value)) {
            return self::anno($now, 'anno_corrente');
        }

        $ref = CarbonImmutable::create((int) $value, 1, 1);

        if (! $ref) {
            return self::anno($now, 'anno_corrente');
        }

        $periodo = self::anno($ref, 'anno');

        return new self('anno', $periodo->start, $periodo->end, $periodo->label, 'anno', $periodo->slug);
    }

    private static function personalizzato(string $dal, string $al, CarbonImmutable $now): self
    {
        $start = self::parse('Y-m-d', $dal);
        $end   = self::parse('Y-m-d', $al);

        // Nessuna delle due date e' utilizzabile: non c'e' un intervallo da mostrare.
        if (! $start && ! $end) {
            return self::mese($now->subMonth(), self::DEFAULT_PRESET);
        }

        // Una sola estremita': l'altra si completa in modo prevedibile invece di
        // rifiutare la richiesta.
        $start = $start ?? $end->startOfMonth();
        $end   = $end ?? $now;

        $start = $start->startOfDay();
        $end   = $end->endOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        if ((int) round($start->startOfDay()->diffInDays($end->startOfDay())) > self::MAX_GIORNI_PERSONALIZZATO) {
            $start = $end->subDays(self::MAX_GIORNI_PERSONALIZZATO)->startOfDay();
        }

        return new self(
            'personalizzato',
            $start,
            $end,
            'Dal ' . $start->format('d/m/Y') . ' al ' . $end->format('d/m/Y'),
            'personalizzato',
            $start->format('Ymd') . '-' . $end->format('Ymd'),
        );
    }

    private static function parse(string $format, string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat($format, $value);
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat accetta anche "2026-13": normalizza a gennaio 2027 senza
        // protestare. Il confronto con il valore rigenerato scarta questi casi.
        return ($parsed && $parsed->format($format) === $value) ? $parsed : null;
    }

    private static function ucfirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }
}
