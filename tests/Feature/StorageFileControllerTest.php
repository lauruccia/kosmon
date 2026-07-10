<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Route fallback /storage/{path}: serve i file del disco "public" via PHP
 * quando il symlink public/storage non esiste (hosting condiviso con webroot
 * separata dall'app — es. public_html vs kosmon_git).
 */
class StorageFileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_serves_an_existing_public_disk_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('companies/test-uuid/logo.png', 'fake-png-content');

        $response = $this->get('/storage/companies/test-uuid/logo.png');

        $response->assertOk();
        $this->assertSame('fake-png-content', $response->streamedContent());
        $this->assertStringContainsString('max-age=604800', (string) $response->headers->get('Cache-Control'));
    }

    public function test_missing_file_returns_404(): void
    {
        Storage::fake('public');

        $this->get('/storage/companies/nope/missing.png')->assertNotFound();
    }

    public function test_path_traversal_is_blocked(): void
    {
        Storage::fake('public');

        // Tentativo di uscire dalla root del disco public: mai 200, mai contenuto.
        $this->get('/storage/../.env')->assertNotFound();
        $this->get('/storage/companies/..%2F..%2F.env')->assertNotFound();
    }

    public function test_nested_branding_files_are_served(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.webp', 'brand');

        $this->get('/storage/branding/logo.webp')->assertOk();
    }
}
