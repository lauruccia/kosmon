<?php

namespace Tests\Feature;

use App\Support\SchemaIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B7 (31/08) — il sostituto di `dropIndexIfExists()` e del `PRAGMA` sparato su
 * qualsiasi driver.
 *
 * Questi test girano sul driver della suite (SQLite). La prova vera che
 * `php artisan migrate` arrivi in fondo su MySQL non puo' stare qui: la fa il
 * job `test-mysql` di .github/workflows/ci.yml, che esiste dal 12/06 ed era
 * rosso proprio su questo.
 */
class SchemaIndexTest extends TestCase
{
    use RefreshDatabase;

    /** Indice creato da una migration: c'e'. */
    public function test_it_sees_an_index_that_exists(): void
    {
        $this->assertTrue(SchemaIndex::exists('mlm_point_ledger', 'mlm_point_ledger_source_unique'));
    }

    public function test_it_does_not_invent_an_index_that_does_not_exist(): void
    {
        $this->assertFalse(SchemaIndex::exists('mlm_point_ledger', 'indice_che_non_esiste'));
    }

    /**
     * Tabella inesistente = nessun indice, senza eccezione: un `down()` deve
     * poter girare anche dopo che la tabella e' stata rimossa altrove.
     */
    public function test_a_missing_table_has_no_indexes(): void
    {
        $this->assertFalse(SchemaIndex::exists('tabella_che_non_esiste', 'qualunque'));
    }

    public function test_it_drops_an_existing_index(): void
    {
        SchemaIndex::dropIfExists('mlm_point_ledger', 'mlm_point_ledger_source_unique');

        $this->assertFalse(SchemaIndex::exists('mlm_point_ledger', 'mlm_point_ledger_source_unique'));
    }

    /**
     * Il punto dell'intera classe: chiedere di rimuovere un indice che non c'e'
     * NON deve sollevare niente. E' cio' che `dropIndexIfExists()` prometteva e
     * che in Laravel 12 non esiste piu'.
     */
    public function test_dropping_a_missing_index_is_silent(): void
    {
        SchemaIndex::dropIfExists('mlm_point_ledger', 'indice_che_non_esiste');
        SchemaIndex::dropIfExists('tabella_che_non_esiste', 'indice_che_non_esiste');

        $this->assertTrue(true, 'Nessuna eccezione sollevata.');
    }
}
