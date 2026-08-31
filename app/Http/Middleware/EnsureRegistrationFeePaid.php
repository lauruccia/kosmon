<?php

namespace App\Http\Middleware;

use App\Services\RegistrationFeeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finche' la quota di iscrizione non e' saldata, l'utente entra e vede tutto
 * — conto, saldo, movimenti, profilo, negozio, aziende — ma non PAGA, non
 * INCASSA e non COMPRA (decisione di Laura, 31/08/2026).
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
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->canAccessBackoffice()) {
            return $next($request);
        }

        if (! app(RegistrationFeeService::class)->isDueFor($user)) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if ($routeName === null || ! $this->bloccata($routeName)) {
            return $next($request);
        }

        $messaggio = 'Per usare questa funzione devi prima saldare la quota di iscrizione.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $messaggio], 403);
        }

        return redirect()->route('portal.registration-fee.show')
            ->with('portal_error', $messaggio);
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
