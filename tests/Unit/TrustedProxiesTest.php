<?php

namespace Tests\Unit;

use App\Support\TrustedProxies;
use PHPUnit\Framework\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        parent::tearDown();
    }

    private function setEnv(?string $value): void
    {
        if ($value === null) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

            return;
        }

        putenv("TRUSTED_PROXIES={$value}");
        $_ENV['TRUSTED_PROXIES'] = $value;
        $_SERVER['TRUSTED_PROXIES'] = $value;
    }

    public function test_default_quando_env_assente_usa_loopback_e_reti_private(): void
    {
        $this->setEnv(null);

        $proxies = TrustedProxies::resolve();

        $this->assertIsArray($proxies);
        $this->assertContains('127.0.0.1', $proxies);
        $this->assertContains('::1', $proxies);
        $this->assertContains('10.0.0.0/8', $proxies);
        $this->assertNotContains('*', $proxies);
    }

    public function test_env_vuoto_ricade_sul_default_sicuro(): void
    {
        $this->setEnv('   ');

        $this->assertIsArray(TrustedProxies::resolve());
    }

    public function test_csv_viene_parsato_in_lista_pulita(): void
    {
        $this->setEnv('173.245.48.0/20, 103.21.244.0/22 ,127.0.0.1');

        $this->assertSame(
            ['173.245.48.0/20', '103.21.244.0/22', '127.0.0.1'],
            TrustedProxies::resolve(),
        );
    }

    public function test_wildcard_esplicito_e_rispettato_come_opt_in(): void
    {
        $this->setEnv('*');

        $this->assertSame('*', TrustedProxies::resolve());
    }
}
