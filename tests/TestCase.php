<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Helper: autentica come utente e imposta automaticamente la sessione 2FA come verificata.
     *
     * In produzione gli utenti completano il challenge 2FA o configurano una passkey.
     * Nei test questa sessione viene pre-impostata per non richiedere aggiornamenti
     * in tutti i 40+ file di test che non testano specificamente il flusso 2FA.
     *
     * I test che verificano il comportamento del middleware TwoFactorChallenge
     * (es. TwoFactorTest, TwoFactorControllerTest) devono usare actingAs() direttamente
     * senza questo helper, oppure non chiamare withTwoFactorSession().
     */
    protected function actingAsWithSession(
        \App\Models\User $user,
        array $session = [],
        string $guard = 'web',
    ): static {
        return $this->actingAs($user, $guard)->withSession(array_merge([
            'two_factor_verified' => true,
        ], $session));
    }
}
