<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Un addebito automatico non è andato a buon fine.
 *
 * "Non a buon fine" quasi mai vuol dire "rifiutato": nella maggior parte dei
 * casi vuol dire **serve una conferma dell'utente**. È la differenza che tiene
 * in piedi la promessa del piano — sopra il tetto non si blocca l'acquisto, si
 * chiede conferma — e per questo il `reason` viaggia sempre insieme al
 * messaggio: kshop deve poter distinguere "chiedi conferma" da "è finita qui".
 */
class MandateException extends RuntimeException
{
    /**
     * @param array<string, mixed> $extra dati utili al client (es. il tetto)
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 402,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * L'addebito automatico non si può fare, ma l'acquisto sì: basta che
     * l'utente lo confermi in KMoney. In fase 2b la risposta portera' anche
     * `payment_request_uuid` e l'URL di conferma.
     */
    public static function confirmationRequired(string $reason, string $message, array $extra = []): self
    {
        return new self($reason, $message, 402, $extra);
    }

    /**
     * La richiesta è malformata o punta a un venditore che non esiste: non è
     * una questione di conferma, è un errore di chi chiama.
     */
    public static function badRequest(string $reason, string $message, array $extra = []): self
    {
        return new self($reason, $message, 422, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'status'  => $this->status === 402 ? 'confirmation_required' : 'refused',
            'reason'  => $this->reason,
            'message' => $this->getMessage(),
        ], $this->extra);
    }
}
