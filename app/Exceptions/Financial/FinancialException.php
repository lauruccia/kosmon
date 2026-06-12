<?php

namespace App\Exceptions\Financial;

use RuntimeException;

/**
 * Eccezione base per tutti i vincoli finanziari del circuito.
 * Porta sempre un messaggio italiano già pronto per l'utente.
 */
class FinancialException extends RuntimeException
{
    //
}
