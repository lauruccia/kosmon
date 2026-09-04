<?php

namespace App\Services\Fees;

/**
 * Tutto cio' che distingue una quota del circuito dall'altra, in un posto solo
 * (02/09/2026, esteso alla terza quota il 03/09 e alle due leve il 04/09).
 *
 * PERCHE' ESISTE. I due servizi delle prime due quote — i 30 dei privati e i
 * 480 del codice agente — sono nati a un giorno di distanza, il secondo
 * copiando il primo. Il 02/09/2026, ripercorrendo tutte e due le procedure, e'
 * venuto fuori che OTTO dei nove difetti trovati erano la stessa cosa: una
 * correzione fatta su una delle due e mai portata sull'altra — il bonifico che
 * si riprende, il ripescaggio in backoffice, la ricevuta a chi paga, la
 * scadenza dei tentativi. Finche' i servizi restano copie l'uno dell'altro, la
 * prossima divergenza e' solo questione di quando.
 *
 * Da qui questo oggetto: il motore e' uno solo (AbstractFeeService) e le
 * differenze stanno tutte scritte qui, dove si leggono in un colpo d'occhio e
 * dove aggiungerne una e' una scelta esplicita invece di un copia-incolla.
 *
 * DAL 04/09/2026 LE DIFFERENZE VERE SONO SOLO I NOMI. Prima ce n'era una di
 * sostanza, `$emitsKyInEuro`: pagando in euro la quota dei privati faceva
 * emettere KY e quella del codice agente no. Non e' piu' un fatto scritto nel
 * codice — e' una cifra che l'admin decide quota per quota dalla pagina
 * /admin/quote, e zero e' semplicemente il valore che rende la quota un puro
 * incasso. Il flag e' stato tolto invece di lasciato a mentire.
 *
 * Restano i nomi: colonne, chiavi delle impostazioni, kind dei movimenti,
 * eventi dell'audit log, notifiche. Sembrano cosmetici e non lo sono — sono la
 * traccia che permette, fra sei mesi, di sapere quale delle tre quote ha mosso
 * quei soldi.
 *
 * LE DUE LEVE, e perche' i loro nomi stanno qui. Ogni quota ha un default nel
 * pannello (`$kyCreditSetting`, `$kyAllowanceSetting`) e un ripiego per singolo
 * utente sulla sua scheda (`$kyCreditOverrideColumn`,
 * `$kyAllowanceOverrideColumn`). Le colonne sono separate per quota — non una
 * sola condivisa — perche' le tre quote sono attive insieme e riguardano
 * persone diverse: la stessa persona puo' avere un trattamento sui privati e
 * nessuno sugli agenti, e con una colonna sola non si potrebbe scrivere.
 */
final class FeeDefinition
{
    /**
     * @param class-string $paymentClass          modello del tentativo di pagamento
     * @param string $dueColumn                   colonna su `users` con l'importo dovuto
     * @param string $paidAtColumn                colonna su `users` con la data del saldo
     * @param string $allowanceColumn             colonna su `users` con il fido REALMENTE concesso
     * @param string $auditPrefix                 prefisso degli eventi nell'audit log
     * @param string $idempotencyPrefix           prefisso della idempotency_key dei movimenti
     * @param class-string $paidNotification      ricevuta a chi salda
     * @param class-string $cancelledNotification avviso a chi si vede annullare la quota
     */
    public function __construct(
        public readonly string $paymentClass,
        public readonly string $dueColumn,
        public readonly string $paidAtColumn,
        public readonly string $allowanceColumn,
        public readonly string $auditPrefix,
        public readonly string $idempotencyPrefix,
        public readonly string $paidNotification,
        public readonly string $cancelledNotification,
        /**
         * Quanti KY riceve chi paga in EURO: chiave su `system_settings` (il
         * default per tutti) e colonna su `users` (il ripiego per uno solo,
         * NULL = segui il default).
         */
        public readonly string $kyCreditSetting,
        public readonly string $kyCreditOverrideColumn,
        /**
         * Chi paga in KY riceve il fido aggiuntivo pari alla quota? Stessa
         * coppia: default nel pannello, ripiego sul singolo utente. Sul
         * pannello il NULL vale ACCESO — vedi
         * AbstractFeeService::kyAllowanceEnabledFor().
         */
        public readonly string $kyAllowanceSetting,
        public readonly string $kyAllowanceOverrideColumn,
        /** Movimento dell'addebito in KY. */
        public readonly string $kyTransferKind,
        public readonly string $kyTransferDescription,
        /** Movimento della restituzione in KY a chi paga in euro (se maggiore di zero). */
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
        /** Il messaggio quando si prova a dare un trattamento a chi la quota non riguarda. */
        public readonly string $treatmentNotApplicableMessage,
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
