<?php

namespace App\Models\Contracts;

/**
 * Un tentativo di pagamento di una quota del circuito (02/09/2026).
 *
 * Lo implementano RegistrationFeePayment (i 30 dei privati) e
 * AgentCodeFeePayment (i 480 del codice agente), che sono due tabelle gemelle
 * tenute separate per scelta di Laura: le due quote sono attive insieme e
 * vanno lette come due cose distinte.
 *
 * Questa interfaccia esiste perche' il motore comune (AbstractFeeService)
 * possa chiedere a un pagamento in che stato e', senza sapere quale delle due
 * quote sia. Dichiara SOLO le domande che il motore fa davvero: se un giorno
 * ne servisse un'altra, va aggiunta qui — ed e' il punto in cui ci si accorge
 * che le due tabelle stanno divergendo.
 */
interface FeePayment
{
    /** Saldata: la risposta e' stata data, non ci si torna sopra. */
    public function isCompleted(): bool;

    /** Annullata dal backoffice, o chiusa insieme al percorso: risposta data. */
    public function isCancelled(): bool;

    /** In attesa di un pagamento istantaneo (carta, PayPal). */
    public function isPending(): bool;

    /** In attesa di un bonifico: aspettare e' il suo mestiere. */
    public function isPendingBankTransfer(): bool;

    /** Pagata (o da pagare) in euro veri e non con il saldo KY. */
    public function isPaidInEuro(): bool;
}
