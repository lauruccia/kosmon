<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication — richiede una re-verifica recente (OTP, password o
 * passkey) prima di permettere operazioni sensibili: cambio password,
 * disattivazione 2FA, revoca token API, modifica email, pagamenti sopra soglia.
 *
 * Algoritmo:
 *  1. Se in sessione c'è 'step_up_verified_at' ed è entro STEP_UP_WINDOW_MINUTES,
 *     lascia passare.
 *  2. Altrimenti reindirizza a /profilo/conferma-identita passando il returnUrl.
 *
 * ATTENZIONE — questa classe è l'UNICO posto dove la finestra dello step-up si
 * scrive e si legge. Chi deve marcare la sessione chiama markVerified(), chi deve
 * controllarla chiama isVerified(). Non reimplementare il confronto altrove: è
 * esattamente la duplicazione (middleware + due punti di PortalController) che
 * aveva tenuto in vita per mesi il bug qui sotto.
 *
 * Il bug corretto il 28/08/2026 era doppio, e i due pezzi si coprivano a vicenda:
 *  a) si salvava in sessione l'oggetto Carbon di now(), non un timestamp. Passato
 *     a Carbon::createFromTimestamp() veniva convertito in stringa
 *     ("2026-08-28 14:32:11") e Carbon ne SOMMA i gruppi di cifre
 *     (2026+8+28+14+32+11 = 2119): l'istante della verifica diventava il
 *     1° gennaio 1970.
 *  b) il confronto era `now()->diffInMinutes($passato)`, che in Carbon 3 è
 *     firmato e quindi negativo: sempre minore di 15.
 * Risultato: una conferma d'identità valeva per tutta la sessione (SESSION_LIFETIME).
 */
class RequireStepUp
{
    public const STEP_UP_WINDOW_MINUTES = 15;

    public const SESSION_KEY = 'step_up_verified_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isVerified($request)) {
            // Salva l'URL di destinazione per il redirect post-verifica
            $request->session()->put('step_up_return_url', $request->fullUrl());

            return redirect()->route('portal.step-up.show')
                ->with('step_up_reason', 'Per continuare devi confermare la tua identità.');
        }

        return $next($request);
    }

    /**
     * Marca la sessione come verificata adesso. SEMPRE un timestamp intero:
     * mai l'oggetto Carbon (vedi la nota in cima alla classe).
     */
    public static function markVerified(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
    }

    /** Cancella la verifica: la prossima operazione sensibile la richiederà di nuovo. */
    public static function clearVerified(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /** La sessione ha una conferma d'identità ancora dentro la finestra? */
    public static function isVerified(Request $request): bool
    {
        return self::isWithinWindow($request->session()->get(self::SESSION_KEY));
    }

    /**
     * Il valore in sessione è un timestamp valido e recente?
     *
     * Tutto ciò che non è un intero (o una stringa di sole cifre) vale "non
     * verificato": comprese le sessioni aperte prima di questo fix, che
     * contengono un oggetto Carbon. Scadere è la direzione sicura — al massimo
     * si riconferma l'identità una volta.
     */
    public static function isWithinWindow(mixed $verifiedAt): bool
    {
        if (is_string($verifiedAt) && ctype_digit($verifiedAt)) {
            $verifiedAt = (int) $verifiedAt;
        }

        if (! is_int($verifiedAt)) {
            return false;
        }

        $moment = Carbon::createFromTimestamp($verifiedAt);

        // Timestamp nel futuro = valore incoerente, non lo si tratta come valido.
        if ($moment->isFuture()) {
            return false;
        }

        return $moment->addMinutes(self::STEP_UP_WINDOW_MINUTES)->isFuture();
    }
}
