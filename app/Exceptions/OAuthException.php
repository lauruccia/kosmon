<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Errore di protocollo OAuth2.
 *
 * Porta con sé il codice previsto dalla specifica (`invalid_grant`,
 * `invalid_client`, …) perché è quello che le librerie client sanno leggere:
 * la descrizione in italiano è per noi, il codice è per loro.
 */
class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description = '',
        public readonly int $status = 400,
    ) {
        parent::__construct($description !== '' ? $description : $error);
    }

    /** Client sconosciuto o segreto sbagliato. */
    public static function invalidClient(string $description): self
    {
        return new self('invalid_client', $description, 401);
    }

    /** Codice o refresh token non valido, scaduto, già usato o non tuo. */
    public static function invalidGrant(string $description): self
    {
        return new self('invalid_grant', $description, 400);
    }

    /** Parametri mancanti o malformati. */
    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description, 400);
    }

    /** Scope inesistente o non concesso a questo client. */
    public static function invalidScope(string $description): self
    {
        return new self('invalid_scope', $description, 400);
    }

    /** Grant type diverso da quelli implementati. */
    public static function unsupportedGrantType(string $description): self
    {
        return new self('unsupported_grant_type', $description, 400);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function toArray(): array
    {
        return [
            'error'             => $this->error,
            'error_description' => $this->getMessage(),
        ];
    }
}
