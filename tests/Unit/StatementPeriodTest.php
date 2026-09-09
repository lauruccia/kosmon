<?php

namespace Tests\Unit;

use App\Support\StatementPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Le scelte rapide dell'estratto conto.
 *
 * Qui si difende una cosa sola ma cruciale: **il periodo che l'etichetta
 * promette e' esattamente il periodo che viene estratto**. "Trimestre corrente"
 * che parte dal giorno sbagliato non e' un bug che si nota guardando il PDF —
 * si nota mesi dopo, quando il commercialista somma due trimestri e trova un
 * movimento contato due volte.
 *
 * Il "adesso" e' sempre passato esplicitamente: un test che dipende dalla data
 * di esecuzione fallisce a capodanno e a fine trimestre, cioe' proprio nei
 * giorni in cui questa classe conta di piu'.
 */
class StatementPeriodTest extends TestCase
{
    private function adesso(): CarbonImmutable
    {
        // Meta' del 2° trimestre 2026, giorno qualunque.
        return CarbonImmutable::create(2026, 5, 14, 16, 30);
    }

    // ── Scelte rapide ───────────────────────────────────────────────────────

    public function test_mese_corrente_copre_il_mese_intero(): void
    {
        $p = StatementPeriod::make('mese_corrente', [], $this->adesso());

        $this->assertSame('2026-05-01 00:00:00', $p->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-31 23:59:59', $p->end->format('Y-m-d H:i:s'));
        $this->assertSame('Maggio 2026', $p->label);
        $this->assertSame('Estratto conto mensile', $p->documentTitle());
        $this->assertSame('2026-05', $p->fileSlug());
    }

    public function test_mese_scorso(): void
    {
        $p = StatementPeriod::make('mese_precedente', [], $this->adesso());

        $this->assertSame('2026-04-01', $p->start->format('Y-m-d'));
        $this->assertSame('2026-04-30', $p->end->format('Y-m-d'));
    }

    public function test_trimestre_corrente_va_da_aprile_a_giugno(): void
    {
        $p = StatementPeriod::make('trimestre_corrente', [], $this->adesso());

        $this->assertSame('2026-04-01', $p->start->format('Y-m-d'));
        $this->assertSame('2026-06-30', $p->end->format('Y-m-d'));
        $this->assertStringContainsString('2° trimestre 2026', $p->label);
        $this->assertSame('Estratto conto trimestrale', $p->documentTitle());
        $this->assertSame('2026-T2', $p->fileSlug());
    }

    public function test_trimestre_scorso_e_il_primo_non_il_secondo(): void
    {
        // Il rischio e' `subQuarter()` su un 14 maggio: cade il 14 febbraio, che
        // e' nel 1° trimestre solo per fortuna. Qui si parte dall'inizio del
        // trimestre corrente e si torna indietro di un giorno.
        $p = StatementPeriod::make('trimestre_precedente', [], $this->adesso());

        $this->assertSame('2026-01-01', $p->start->format('Y-m-d'));
        $this->assertSame('2026-03-31', $p->end->format('Y-m-d'));
    }

    public function test_trimestre_scorso_a_gennaio_torna_all_anno_prima(): void
    {
        $p = StatementPeriod::make('trimestre_precedente', [], CarbonImmutable::create(2026, 1, 7));

        $this->assertSame('2025-10-01', $p->start->format('Y-m-d'));
        $this->assertSame('2025-12-31', $p->end->format('Y-m-d'));
    }

    public function test_anno_in_corso_e_anno_precedente(): void
    {
        $corrente = StatementPeriod::make('anno_corrente', [], $this->adesso());
        $prima    = StatementPeriod::make('anno_precedente', [], $this->adesso());

        $this->assertSame('2026-01-01', $corrente->start->format('Y-m-d'));
        $this->assertSame('2026-12-31', $corrente->end->format('Y-m-d'));
        $this->assertSame('Anno 2026', $corrente->label);
        $this->assertSame('Estratto conto annuale', $corrente->documentTitle());

        $this->assertSame('2025-01-01', $prima->start->format('Y-m-d'));
        $this->assertSame('2025-12-31', $prima->end->format('Y-m-d'));
    }

    public function test_ultimi_dodici_mesi_ne_contiene_dodici_non_tredici(): void
    {
        $p = StatementPeriod::make('ultimi_12_mesi', [], $this->adesso());

        $this->assertSame('2025-06-01', $p->start->format('Y-m-d'));
        $this->assertSame('2026-05-31', $p->end->format('Y-m-d'));
    }

    // ── Periodi specifici ───────────────────────────────────────────────────

    public function test_mese_specifico(): void
    {
        $p = StatementPeriod::make('mese', ['mese' => '2026-02'], $this->adesso());

        $this->assertSame('2026-02-01', $p->start->format('Y-m-d'));
        $this->assertSame('2026-02-28', $p->end->format('Y-m-d'));
        $this->assertSame('Febbraio 2026', $p->label);
    }

    public function test_trimestre_e_anno_specifici(): void
    {
        $t = StatementPeriod::make('trimestre', ['trimestre' => '2025-T4'], $this->adesso());
        $a = StatementPeriod::make('anno', ['anno' => '2024'], $this->adesso());

        $this->assertSame('2025-10-01', $t->start->format('Y-m-d'));
        $this->assertSame('2025-12-31', $t->end->format('Y-m-d'));

        $this->assertSame('2024-01-01', $a->start->format('Y-m-d'));
        $this->assertSame('2024-12-31', $a->end->format('Y-m-d'));
        $this->assertSame('2024', $a->fileSlug());
    }

    public function test_intervallo_personalizzato(): void
    {
        $p = StatementPeriod::make('personalizzato', ['dal' => '2026-03-10', 'al' => '2026-04-05'], $this->adesso());

        $this->assertSame('2026-03-10 00:00:00', $p->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-05 23:59:59', $p->end->format('Y-m-d H:i:s'));
        $this->assertSame('Dal 10/03/2026 al 05/04/2026', $p->label);
        $this->assertSame('20260310-20260405', $p->fileSlug());
    }

    public function test_date_invertite_si_raddrizzano_invece_di_dare_un_estratto_vuoto(): void
    {
        $p = StatementPeriod::make('personalizzato', ['dal' => '2026-04-05', 'al' => '2026-03-10'], $this->adesso());

        $this->assertSame('2026-03-10', $p->start->format('Y-m-d'));
        $this->assertSame('2026-04-05', $p->end->format('Y-m-d'));
    }

    // ── Ingressi sporchi: nessuno di questi deve far esplodere la pagina ────

    public function test_periodo_sconosciuto_ricade_sul_mese_scorso(): void
    {
        $p = StatementPeriod::make('periodo_inventato', [], $this->adesso());

        $this->assertSame('mese_precedente', $p->preset);
        $this->assertSame('2026-04-01', $p->start->format('Y-m-d'));
    }

    public function test_mese_inesistente_non_diventa_gennaio_dell_anno_dopo(): void
    {
        // createFromFormat('Y-m', '2026-13') non protesta: normalizza a gennaio
        // 2027. Un estratto "Gennaio 2027" chiesto per errore e' peggio di un
        // ripiego dichiarato.
        $p = StatementPeriod::make('mese', ['mese' => '2026-13'], $this->adesso());

        $this->assertSame('2026-04-01', $p->start->format('Y-m-d'));
    }

    public function test_trimestre_fuori_scala_ricade_sul_trimestre_corrente(): void
    {
        $p = StatementPeriod::make('trimestre', ['trimestre' => '2026-T9'], $this->adesso());

        $this->assertSame('2026-04-01', $p->start->format('Y-m-d'));
    }

    public function test_intervallo_senza_date_ricade_sul_mese_scorso(): void
    {
        $p = StatementPeriod::make('personalizzato', ['dal' => '', 'al' => ''], $this->adesso());

        $this->assertSame('2026-04-01', $p->start->format('Y-m-d'));
        $this->assertSame('2026-04-30', $p->end->format('Y-m-d'));
    }

    public function test_una_sola_data_completa_l_altra(): void
    {
        $soloDal = StatementPeriod::make('personalizzato', ['dal' => '2026-05-01', 'al' => ''], $this->adesso());
        $soloAl  = StatementPeriod::make('personalizzato', ['dal' => '', 'al' => '2026-03-20'], $this->adesso());

        $this->assertSame('2026-05-01', $soloDal->start->format('Y-m-d'));
        $this->assertSame('2026-05-14', $soloDal->end->format('Y-m-d'));

        $this->assertSame('2026-03-01', $soloAl->start->format('Y-m-d'));
        $this->assertSame('2026-03-20', $soloAl->end->format('Y-m-d'));
    }

    public function test_intervallo_smisurato_viene_tagliato_a_cinque_anni(): void
    {
        $p = StatementPeriod::make('personalizzato', ['dal' => '1990-01-01', 'al' => '2026-05-14'], $this->adesso());

        $this->assertSame('2021-05-14', $p->start->format('Y-m-d'));
        $this->assertSame('2026-05-14', $p->end->format('Y-m-d'));
    }

    // ── Conservazione del periodo nei link ──────────────────────────────────

    public function test_i_parametri_rigenerano_lo_stesso_periodo(): void
    {
        foreach ([
            ['mese', ['mese' => '2026-02']],
            ['trimestre', ['trimestre' => '2025-T4']],
            ['anno', ['anno' => '2024']],
            ['personalizzato', ['dal' => '2026-03-10', 'al' => '2026-04-05']],
            ['anno_precedente', []],
        ] as [$preset, $input]) {
            $primo = StatementPeriod::make($preset, $input, $this->adesso());
            $params = $primo->queryParams();
            $secondo = StatementPeriod::make($params['periodo'], $params, $this->adesso());

            $this->assertSame($primo->start->format('Y-m-d'), $secondo->start->format('Y-m-d'), $preset);
            $this->assertSame($primo->end->format('Y-m-d'), $secondo->end->format('Y-m-d'), $preset);
        }
    }

    public function test_riconosce_quando_il_periodo_supera_il_mese(): void
    {
        $this->assertFalse(StatementPeriod::make('mese_corrente', [], $this->adesso())->spansMultipleMonths());
        $this->assertTrue(StatementPeriod::make('trimestre_corrente', [], $this->adesso())->spansMultipleMonths());
        $this->assertTrue(StatementPeriod::make('anno_corrente', [], $this->adesso())->spansMultipleMonths());
    }
}
