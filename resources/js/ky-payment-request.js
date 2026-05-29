/**
 * ky-payment-request.js
 *
 * Progressive enhancement: intercetta il form di pagamento KY
 * e mostra il native browser Payment Request sheet quando disponibile.
 *
 * Se il browser non supporta Payment Request API o non ha handler registrati,
 * ricade silenziosamente sul normale form submit.
 */

const KY_PAYMENT_METHOD = window.location.origin + '/paga/handler';

/**
 * Registra KMoney come Payment Handler nel Service Worker.
 * Va chiamata una sola volta dopo che l'SW è pronto.
 */
export async function registerKyPaymentHandler() {
    if (!navigator.serviceWorker) return;
    try {
        const reg = await navigator.serviceWorker.ready;
        if ('paymentManager' in reg) {
            await reg.paymentManager.instruments.set('ky-default', {
                name:   'KMoney (KY)',
                icons:  [{ src: '/assets/brand/icon-192.png', sizes: '192x192', type: 'image/png' }],
                method: KY_PAYMENT_METHOD,
            });
        }
    } catch (e) {
        // Non critico — l'app funziona senza
    }
}

/**
 * Tenta il pagamento via Payment Request API.
 * Ritorna true se completato via PRA, false se va usato il form normale.
 *
 * @param {object} opts
 * @param {number}  opts.amount       - KY
 * @param {string}  opts.label        - nome commerciante / descrizione
 * @param {string}  opts.description  - causale (opzionale)
 * @param {string}  opts.prToken      - PaymentRequest.token (per eseguire il transfer)
 * @param {number}  opts.secondsLeft  - secondi alla scadenza
 */
export async function payViaRequestApi({ amount, label, description, prToken, secondsLeft }) {
    if (typeof PaymentRequest === 'undefined') return false;

    const methodData = [{
        supportedMethods: KY_PAYMENT_METHOD,
        data: {
            pr_token:    prToken,
            seconds_left: secondsLeft,
        },
    }];

    const details = {
        id:           'ky-' + prToken,
        displayItems: [
            { label: description || label, amount: { currency: 'KY', value: String(amount) } },
        ],
        total: {
            label: label,
            amount: { currency: 'KY', value: String(amount) },
        },
    };

    let request;
    try {
        request = new PaymentRequest(methodData, details);
    } catch (e) {
        return false;
    }

    // Verifica se il browser ha handler per questo metodo
    let canPay = false;
    try {
        canPay = await request.canMakePayment();
    } catch (e) {
        return false;
    }

    if (!canPay) return false;

    try {
        const response = await request.show();
        // Transfer già eseguito dalla handler window — completa
        await response.complete('success');
        return true;
    } catch (e) {
        if (e.name === 'AbortError') return false; // utente ha annullato → usa form
        return false;
    }
}

/**
 * Inizializza il bottone di pagamento con PRA come opzione preferita.
 * Chiama questa funzione sulla pagina pay-request.blade.php.
 *
 * @param {object} opts - stessi parametri di payViaRequestApi + redirectUrl
 */
export async function initPayButton(opts) {
    const btn = document.getElementById('pay-btn');
    if (!btn) return;

    // Registra l'handler (no-op se già registrato)
    registerKyPaymentHandler().catch(() => {});

    // Testa se PRA è disponibile senza mostrare nulla
    if (typeof PaymentRequest === 'undefined') return;

    let request, canPay = false;
    try {
        request = new PaymentRequest(
            [{ supportedMethods: KY_PAYMENT_METHOD }],
            { total: { label: opts.label, amount: { currency: 'KY', value: String(opts.amount) } } }
        );
        canPay = await request.canMakePayment();
    } catch (e) { /* fallback silenzioso */ }

    if (!canPay) return;

    // PRA disponibile: badge sul bottone
    btn.setAttribute('data-pra', '1');
    const badge = document.createElement('span');
    badge.textContent = ' · Biometria';
    badge.style.cssText = 'font-size:11px;opacity:.7;font-weight:500;';
    btn.appendChild(badge);

    // Intercetta click
    btn.addEventListener('click', async (e) => {
        if (!btn.getAttribute('data-pra')) return; // già disabilitato
        e.preventDefault();
        e.stopImmediatePropagation();
        btn.removeAttribute('data-pra');

        const paid = await payViaRequestApi(opts);

        if (paid) {
            // Mostra conferma e redirect
            btn.textContent = '✓ Pagamento completato!';
            btn.style.background = '#059669';
            setTimeout(() => { window.location.href = opts.redirectUrl || '/dashboard'; }, 1500);
        } else {
            // Fallback: submit normale
            btn.closest('form')?.submit();
        }
    }, { once: true });
}
