<?php

namespace App\Services\Fees;

/**
 * Tutto cio' che distingue una quota del circuito dall'altra, in un posto solo
 * (02/09/2026).
 *
 * PERCHE' ESISTE. I due servizi delle quote — i 30 dei privati e i 480 del
 * codice agente — sono nati a un giorno di distanza, il secondo copiando il
 * primo. Il 02/09/2026, ripercorrendo tutte e due le procedure, e' venuto
 * fuori che OTTO dei nove difetti trovati erano la stessa cosa: una correzione
 * fatta su una delle due e mai portata sull'altra — il bonifico che si
 * riprende, il ripescaggio in backoffice, la ricevuta a chi paga, la scadenza
 * dei tentativi. Finche' i due servizi restano due copie, la prossima
 * divergenza e' solo questione di quando.
 *
 * Da qui questo oggetto: il motore e' uno solo (AbstractFeeService) e le
 * differenze stanno tutte scritte qui, dove si leggono in un colpo d'occhio e
 * dove aggiungerne una e' una scelta esplicita invece di un copia-incolla.
 *
 * LE DIFFERENZE VERE SONO DUE, e sono tutte e due qui dentro:
 *
 *  1. `$emitsKyInEuro`. Pagando in EURO, la quota dei privati fa emettere 30 KY
 *     dal conto di sistema all'utente — in euro la quota non e' un costo, hai
 *     comprato KY. La quota del codice agente no: i 480 sono il prezzo della
 *     nomina, KNM incassa e il conto dell'agente non viene toccato affatto.
 *  2. I nomi: colonne, kind dei movimenti, eventi dell'audit log, notifiche.
 *     Sembrano cosmetici e non lo sono — sono la traccia che permette, fra sei
 *     mesi, di sapere quale delle due quote ha mosso quei soldi.
 *
 * Tutto il resto — i quattro metodi di pagamento, il fido aggiuntivo per il
 * pagamento in KY, l'idempotenza, lo storno, il ripescaggio — e' identico, e
 * adesso e' scritto una volta sola.
 */
final class FeeDefinition
{
    /**
     * @param class-string $paymentClass        modello del tentativo di pagamento
     * @param string $dueColumn                 colonna su `users` con l'importo dovuto
     * @param string $paidAtColumn              colonna su `users` con la data del saldo
     * @param string $allowanceColumn           colonna su `users` con il fido aggiuntivo
     * @param string $auditPrefix               prefisso degli eventi nell'audit log
     * @param string $idempotencyPrefix         prefisso della idempotency_key dei movimenti
     * @param bool   $emitsKyInEuro             pagando in euro il circuito emette KY?
     * @param class-string $paidNotification    ricevuta a chi salda
     * @param class-string $cancelledNotification avviso a chi si vede annullare la quota
     */
    public function __construct(
        public readonly string $paymentClass,
        public readonly string $dueColumn,
        public readonly string $paidAtColumn,
        public readonly string $allowanceColumn,
        public readonly string $auditPrefix,
        public readonly string $idempotencyPrefix,
        public readonly bool $emitsKyInEuro,
        public readonly string $paidNotification,
        public readonly string $cancelledNotification,
        /** Movimento dell'addebito in KY. */
        public readonly string $kyTransferKind,
        public readonly string $kyTransferDescription,
        /** Movimento di emissione dei KY quando si paga in euro (solo se $emitsKyInEuro). */
        public readonly string $creditTransferKind,
        public readonly string $creditTransferDescription,
        /** Movimento di storno, quando la quota viene annullata. */
        public readonly string $reversalTransferKind,
        public readonly string $reversalTransferDescription,
        /** Il messaggio che vede chi prova a pagare una quota che non deve. */
        public readonly string $notDueMessage,
        /** Il messaggio del ripescaggio su una quota annullata. */
        public readonly string $retryOnCancelledMessage,
        /** L'inizio del messaggio quando il ripescaggio fallisce di nuovo. */
        public readonly string $retryFailedMessage,
    ) {
    }

    /** Nome completo di un evento dell'audit log per questa quota. */
    public function event(string $suffix): string
    {
        return $this->auditPrefix . '.' . $suffix;
    }

    /** La idempotency_key di un movimento, per questa quota e questo pagamento. */
    public function idempotencyKey(string $uuid, string $suffix = ''): string
    {
        return $this->idempotencyPrefix . ($suffix === '' ? '' : $suffix . '_') . $uuid;
    }
}
