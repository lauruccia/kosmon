<?php

namespace App\Http\Middleware;

use App\Services\AgentCodeFeeService;
use App\Services\RegistrationFeeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finche' una quota del circuito non e' saldata, l'utente entra e vede tutto
 * — conto, saldo, movimenti, profilo, negozio, aziende — ma non PAGA, non
 * INCASSA e non COMPRA (decisione di Laura, 31/08/2026).
 *
 * LE QUOTE SONO DUE e possono essere dovute dalla stessa persona in momenti
 * diversi: l'iscrizione dei privati (alla registrazione) e il codice agente
 * (all'approvazione della richiesta). Dal 02/09/2026 non fermano le stesse
 * cose: la prima ferma sempre il conto, la seconda solo a chi nel circuito
 * non e' ancora entrato pagando (vedi handle()). Il nome della classe e' rimasto quello
 * della prima, che e' gia' pronta per la produzione e non si e' voluta
 * rimaneggiare; l'elenco delle rotte bloccate invece e' e deve restare UNO
 * SOLO, perche' e' la stessa identica domanda — che cosa puo' fare chi deve
 * dei soldi al circuito — e due elenchi gemelli divergerebbero al primo
 * cambiamento.
 *
 * PERCHE' UNA LISTA E NON UN MIDDLEWARE SPARSO SULLE ROTTE. Le rotte del
 * portale sono qualche centinaio dentro un unico gruppo: appendere `quota` a
 * mano su cinquanta di esse vuol dire che la cinquantunesima, aggiunta fra
 * sei mesi, resta scoperta senza che nessuno se ne accorga. Qui invece esiste
 * UN posto solo in cui si legge cosa e' bloccato e cosa no, e un test lo
 * percorre riga per riga.
 *
 * NON e' bloccato di proposito:
 *   - la ricarica (portal.ky-cards.*): chi non ha KY deve poterne comprare,
 *     ed e' anche il modo di arrivare a saldare la quota;
 *   - la pagina della quota stessa e i suoi pagamenti;
 *   - vedere il negozio, il carrello, i propri ordini: guardare non e' comprare.
 */
class EnsureRegistrationFeePaid
{
    /**
     * Radici bloccate: il nome della rotta coincide con una di queste, oppure
     * ci comincia seguito da un punto.
     *
     * @var list<string>
     */
    private const BLOCCATE = [
        // ── Pagare ──────────────────────────────────────────────────────────
        'portal.invia',
        'portal.pay',
        'portal.paga-codice',
        'portal.paga-sonic',
        'portal.payment-handler',
        'portal.pay-request.pay',
        'portal.scheduled-payments.create',
        'portal.scheduled-payments.store',
        'portal.payment-plans.create',
        'portal.payment-plans.store',
        'portal.netting.create',
        'portal.netting.store',
        'portal.netting.accept',
        'portal.credit-note',

        // ── Incassare ───────────────────────────────────────────────────────
        'portal.incasso-qr',
        'portal.incasso-codice',
        'portal.incasso-nfc',
        'portal.incasso-sonic',
        'portal.payment-links.create',
        'portal.payment-links.store',
        'portal.text-requests.create',
        'portal.text-requests.store',

        // ── Comprare ────────────────────────────────────────────────────────
        'portal.cart.add',
        'portal.cart.checkout',
        'portal.shop.buy',
        'portal.shop.orders.pay',

        // ── Autorizzare un'app a pagare al posto tuo (01/09/2026) ────────────
        // Concedere il mandato non muove KY di per se', ma e' il permesso a
        // muoverli: darlo mentre il conto e' fermo vuol dire prepararsi a
        // spendere appena si riapre, e soprattutto vuol dire una pagina che
        // promette una cosa che non funzionera'. L'ADDEBITO vero passa da
        // routes/api.php e non da qui: e' rifiutato in
        // Api\V1\MandateController::charge(), che questo elenco non
        // raggiunge. Le due difese guardano due porte diverse, non la stessa.
        // `deny` resta aperta di proposito: revocare deve essere sempre
        // possibile.
        'oauth.mandate.grant',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->canAccessBackoffice()) {
            return $next($request);
        }

        // Quale quota deve? Se ne deve due, si comincia da quella di
        // iscrizione: e' la prima in ordine di tempo e l'altra non e' nemmeno
        // raggiungibile finche' non ha un conto operativo.
        if (app(RegistrationFeeService::class)->isDueFor($user)) {
            $rotta     = 'portal.registration-fee.show';
            $messaggio = 'Per usare questa funzione devi prima saldare la quota di iscrizione.';
        } elseif (app(AgentCodeFeeService::class)->isDueFor($user)) {
            // LA QUOTA DEL CODICE NON FERMA IL CONTO A TUTTI (02/09/2026,
            // decisione di Laura). Ferma chi nel circuito non ha ancora
            // pagato nessun ingresso — quota di iscrizione SOSPESA, cioe' i
            // 480 sono il suo ingresso. Chi i 30 li aveva gia' pagati, o non
            // li ha mai dovuti perche' iscritto da prima, il conto ce l'ha e
            // continua a usarlo: gli manca solo la firma della nomina, ed e'
            // li' che la strada resta sbarrata (MlmAgentContractController).
            //
            // Bloccarlo qui vorrebbe dire che a un privato gia' operativo
            // chiedere di diventare agente costa il congelamento del conto:
            // il contrario di quello che il percorso dovrebbe essere.
            if (! app(RegistrationFeeService::class)->isSuspendedFor($user)) {
                return $next($request);
            }

            $rotta     = 'portal.mlm.agent-code-fee.show';
            $messaggio = 'Per usare questa funzione devi prima saldare la quota per il codice agente.';
        } else {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if ($routeName === null || ! $this->bloccata($routeName)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $messaggio], 403);
        }

        return redirect()->route($rotta)->with('portal_error', $messaggio);
    }

    private function bloccata(string $routeName): bool
    {
        foreach (self::BLOCCATE as $radice) {
            if ($routeName === $radice || str_starts_with($routeName, $radice . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Esposta per i test: l'elenco e' il contratto di questa classe, e un
     * test che lo legge da qui fallisce quando qualcuno lo accorcia.
     *
     * @return list<string>
     */
    public static function radiciBloccate(): array
    {
        return self::BLOCCATE;
    }
}
