# Integrazione KMoney con e-commerce (WooCommerce / Magento 2)

Documento tecnico per i programmatori incaricati di costruire i moduli di pagamento KMoney per WooCommerce e Magento. Basato sull'analisi del codice di `kmoney-app` (API v1) e sull'audit del plugin WordPress attuale `kmoney_v1.4.5.zip`.

---

## 1. Sintesi

Il plugin WordPress attuale **non parla con `kmoney-app`**. Chiama un'API completamente diversa e legacy (`https://kosmomoney.com/api/*`: `check-user-details`, `kycard-login`, `update-user-amount`, `update-tx-meta`, `refund-transaction`), che non esiste nel codebase Laravel attuale. Va considerato un sistema a parte, non una versione precedente della stessa API.

Il problema più grave non è la migrazione di endpoint: è il **modello di autenticazione**. Il plugin fa scrivere email e password KMoney del cliente in un form sul sito del negoziante e le inoltra server-side a terzi (`kmoney.php` righe 815-908, 1189-1196, 1358-1361). Questo va abbandonato, non riportato nel nuovo modulo. `kmoney-app` oggi ha 2FA, passkey/WebAuthn e step-up auth: un flusso che chiede la password in un `<input>` su un sito esterno vanifica tutto questo, ed espone il negoziante a responsabilità enormi se quel sito viene compromesso.

L'API v1 di `kmoney-app` (`/api/v1/*`), inoltre, **non è pensata per questo caso d'uso**: è un'API lato-negoziante (Bearer token legato a un'azienda) per consultare saldo/movimenti o inviare pagamenti in uscita. Non c'è un endpoint pensato per "il cliente paga il negoziante durante un checkout web".

La buona notizia: `kmoney-app` ha già internamente il pattern corretto per questo — il sistema di **PaymentRequest con pagina di conferma hosted** (`/pay/{token}`, usato da QR/NFC/Sonic/codice). È lo stesso schema di Stripe Checkout o PayPal: il cliente viene reindirizzato sul dominio KMoney, si autentica con le sue credenziali (2FA/passkey inclusi) e conferma l'importo. Va solo esposto via API per un negoziante server-to-server, cosa che oggi non esiste ancora.

---

## 2. Cosa espone oggi l'API v1 di kmoney-app

Base: `https://<dominio-kmoney>/api/v1/`. Auth: header `Authorization: Bearer km_xxxxxxxxxxxx`, generato dal negoziante in `/api-tokens`. Spec OpenAPI pubblica (parziale): `GET /api/openapi.json`.

| Endpoint | Metodo | Ability richiesta | Cosa fa |
|---|---|---|---|
| `/me` | GET | read | Dati azienda + conto principale |
| `/balance` | GET | read | Saldo, fido, massimale, `can_sell`, `allowed_ky_percentages` |
| `/transfers` | GET | read | Lista movimenti (paginata, ultimi booked) |
| `/transfers/{uuid}` | GET | read | Dettaglio movimento |
| `/transfers` | POST | write | **Crea un pagamento in uscita dal conto del negoziante** verso un altro conto KY |
| `/payment-plans` | GET | read | Lista piani rateali |
| `/payment-plans/{uuid}` | GET | read | Dettaglio piano rateale |
| `/payment-requests` | GET | read | Lista richieste di pagamento (in entrata/uscita) |
| `/payment-requests/{uuid}` | GET | read | Dettaglio richiesta di pagamento |

Punti importanti per chi implementa:

- **Importi sempre in centesimi interi** (`amount: 5000` = 50,00 KY). Non inviare mai float.
- Ogni token ha `abilities` (`read`, `write`, o `*`) e può scadere (`expires_at`). `POST /transfers` richiede `write`.
- Rate limit: `throttle:60,1` su tutte le rotte v1, `throttle:10,1` in più su `POST /transfers`.
- `POST /transfers` accetta `to_account` (numero conto KY pubblico, es. `KYB...`) e **richiede `idempotency_key`** — obbligatorio, va generato dal chiamante (UUID) e deve essere stabile sui retry per evitare doppi addebiti.
- `POST /transfers` **non accetta ID interni**, solo il numero conto pubblico — corretto e da rispettare nell'integrazione.
- L'IP del token viene tracciato; un cambio IP notifica il proprietario del token via email. Da tenere presente se l'e-commerce gira su un IP che cambia spesso (es. serverless/CDN) — meglio un IP statico/outbound fisso per il modulo.

**Cosa manca per un checkout e-commerce classico:** non esiste un `POST /payment-requests` per far generare al negoziante — via API, server-to-server — una richiesta di incasso con URL/QR da mostrare al cliente. Oggi quella creazione esiste solo lato portale web (`IncassoQrController::store`, sessione autenticata del negoziante nel browser), non è raggiungibile da un plugin e-commerce headless.

---

## 3. Perché il modello "password del cliente nel form del negoziante" va abbandonato

Il plugin attuale fa così (funzione `custom_processing_function` in `kmoney.php`):

1. Il cliente digita email + password KMoney in un campo del checkout WooCommerce.
2. Il plugin fa `wp_remote_post` a `kycard-login` con quelle credenziali in chiaro.
3. Se il login riesce, chiama `update-user-amount` che addebita il cliente e accredita il venditore, **passando di nuovo la password**.

Problemi, in ordine di gravità:

1. **Le credenziali del cliente transitano e vengono processate da un sito che non è KMoney** (il negoziante, o peggio uno store WooCommerce compromesso/vulnerabile). Un plugin di terze parti vulnerabile, un tema compromesso o un semplice log di debug lasciato acceso (`error_log` è ovunque nel plugin attuale) possono esfiltrare credenziali di pagamento reali.
2. **Bypassa 2FA e passkey**: anche se l'account cliente ha il 2FA attivo su kmoney-app, questo flusso non lo richiede — è un login "shadow" via API che aggira le protezioni pensate apposta per i pagamenti.
3. **Non è compatibile con l'architettura attuale**: `kmoney-app` non ha nemmeno un endpoint API di login per utente finale (l'unica auth API è per token azienda). Il flusso del plugin punta a un sistema legacy separato che tratta email/password come se fosse l'unico fattore.
4. **Nessuna idempotenza visibile** in `update-user-amount` lato plugin (non è nel nostro codebase, quindi non verificabile, ma il plugin non passa un idempotency key robusto — usa solo un retry via sessione PHP).

**Raccomandazione**: qualunque nuovo modulo (WooCommerce o Magento) non deve mai chiedere o processare la password KMoney del cliente. Il cliente si autentica solo ed esclusivamente su un dominio KMoney, con la sessione/2FA/passkey di `kmoney-app`.

---

## 4. Architettura raccomandata: redirect/hosted checkout

Stesso schema concettuale di PayPal Checkout, Stripe Checkout o Satispay: il negoziante crea una richiesta di pagamento lato server, reindirizza il cliente su KMoney, KMoney gestisce l'intera autenticazione/conferma, e notifica il negoziante dell'esito.

```
Cliente               Negozio (WooCommerce/Magento)          kmoney-app
  |                          |                                    |
  | checkout, sceglie KMoney |                                    |
  |------------------------->|                                    |
  |                          | POST /api/v1/payment-requests       |
  |                          | (server-to-server, Bearer token)    |
  |                          |----------------------------------->|
  |                          |<---- {token, pay_url, expires_at} --|
  |  redirect a pay_url      |                                    |
  |<-------------------------|                                    |
  | login KMoney (2FA/passkey), conferma importo                  |
  |---------------------------------------------------------------|
  |                          |   webhook payment_request.paid ---->|
  |                          |<------------------------------------|
  |  redirect di ritorno al negozio (return_url)                  |
  |<----------------------------------------------------------------|
  |                          | verifica stato via GET /payment-requests/{uuid} |
  |                          | (conferma, non fidarsi solo del redirect)       |
```

Punti chiave:

- Il negoziante **non vede mai** credenziali del cliente.
- L'importo e l'idempotenza sono garantiti lato KMoney (stessa logica già usata da QR/NFC/Sonic).
- Il negoziante conferma l'esito in due modi indipendenti (best practice, non fidarsi di uno solo): **webhook** (asincrono, autorevole) + **verifica GET esplicita** dello stato quando il cliente torna sul sito (perché il redirect di ritorno da solo è falsificabile).

### 4.1 Cosa esiste già in kmoney-app e può essere riusato

- **`PaymentRequest`** (model + tabella): ha già `kind` (`qr_dynamic`, `nfc`, `link`, `text`), `token`, `expires_at`, `status` (`pending`/`paid`/`expired`/`cancelled`), `to_account_id`, `amount`, `description`.
- **`/pay/{token}`** (`PaymentRequestController@show` / `@pay`, dentro il gruppo di rotte portale autenticato): pagina di conferma già pronta, con l'intero stack di sicurezza del portale (`auth`, `verified`, `twofactor`, `onboarding`, `contract`). Questa è la pagina su cui reindirizzare il cliente e-commerce.
- **`Webhook` / `SendWebhookJob`**: infrastruttura di webhook già presente, con firma HMAC-SHA256 (`X-KMoney-Signature: sha256=...`) e header `X-KMoney-Event`. Esattamente il meccanismo giusto per notificare l'ordine WooCommerce/Magento.

### 4.2 Cosa manca e va costruito lato kmoney-app (prima di dare l'incarico ai programmatori dei plugin)

1. **`POST /api/v1/payment-requests`** — oggi non esiste (solo GET). Deve: creare un `PaymentRequest` con `to_account_id` = conto del negoziante autenticato dal token, `amount`, `description`, un campo di correlazione lato-merchant (es. `external_reference` = numero ordine WooCommerce/Magento, da aggiungere allo schema se non c'è già un campo libero), `kind = 'link'` o un nuovo kind dedicato es. `ecommerce`, e restituire `{ uuid, token, pay_url, expires_at }` dove `pay_url` è l'URL assoluto verso `/pay/{token}`.
2. **Evento webhook `payment_request.paid`** — oggi `Webhook::EVENTS` non lo include (ha solo `transfer.booked`, `transfer.failed`, `payment_request.approved/rejected` che sono per le *richieste di pagamento testuali*, non per questo flusso). Va aggiunto e disparato quando una `PaymentRequest` passa a `status = paid`.
3. **Verifica: il dispatch webhook non è agganciato agli eventi reali.** `WebhookService::dispatch()` / `dispatchForBoth()` sono definiti ma **non risultano invocati da nessuna parte del codice applicativo** (solo dal pulsante "Test" in `WebhookController`). Vanno collegati nel punto in cui un `Transfer`/`PaymentRequest` cambia stato (dentro `TransferBookingService` o subito dopo, via `DB::afterCommit`, seguendo il pattern già usato per fee/cashback). Senza questo, i webhook non partiranno mai in produzione — è un prerequisito bloccante per un'integrazione e-commerce basata su notifiche asincrone.
4. Un `return_url` / `cancel_url` da passare in creazione (per far tornare il cliente sull'ordine giusto) — non presente nello schema attuale di `PaymentRequest`.
5. Endpoint di verifica esplicita già c'è (`GET /payment-requests/{uuid}`), va solo confermato che il negoziante possa interrogarlo anche per richieste create via API.

Questi 4 punti sono lavoro sul backend `kmoney-app`, propedeutico e separato dal lavoro sui plugin WooCommerce/Magento. Segnalarli come task per il team backend prima di far partire i moduli e-commerce, altrimenti i programmatori dei plugin si troveranno a dover reinventare un flusso non supportato (come è successo con il plugin attuale, che infatti punta a un sistema esterno diverso).

---

## 5. Flusso lato modulo WooCommerce

1. **Creazione gateway**: estendere `WC_Payment_Gateway` (il plugin attuale lo fa già correttamente in `class-wc-gateway-kmoney.php` — quella parte, l'ossatura WooCommerce standard, è riusabile).
2. **Impostazioni gateway**: `kmoney_api_base_url`, `kmoney_api_token` (Bearer token del negoziante, generato da `/api-tokens` su kmoney-app — **da salvare cifrato**, non in chiaro in `wp_options` come oggi fa con `kmoney_account_number`), `kmoney_webhook_secret`.
3. **`process_payment($order_id)`**: invece di gestire lo split KMoney via AJAX prima del "Effettua ordine" (come oggi), chiamare `POST /api/v1/payment-requests` con l'importo dell'ordine (in centesimi), `external_reference = $order->get_order_number()`, `return_url = $order->get_checkout_order_received_url()`. Mettere l'ordine in stato `on-hold`, salvare `payment_request_uuid` come meta ordine, e restituire `redirect => pay_url` ricevuto dalla API (WooCommerce supporta nativamente `result: success, redirect: <url esterno>`).
4. **Endpoint di ritorno**: quando il cliente torna su `return_url`, non fidarsi del redirect — chiamare `GET /api/v1/payment-requests/{uuid}` per leggere lo stato reale prima di mostrare "ordine confermato".
5. **Webhook receiver**: un endpoint REST WordPress (`register_rest_route`) che riceve `payment_request.paid` da kmoney-app, verifica la firma HMAC con il secret configurato, trova l'ordine tramite `external_reference`, e chiama `$order->payment_complete()`.
6. **Rimborsi**: usare `POST /api/v1/transfers` (con `write` ability) per accreditare il cliente, oppure — se lo si vuole esporre — un endpoint di rimborso dedicato lato kmoney-app che richiami `TransferBookingService::refundMerchant()` (esiste già lato backend, va solo esposto via API v1 se non c'è).
7. **Cosa NON portare avanti dal plugin attuale**: il campo password nel checkout, la logica `_percentage`/split-payment per categoria prodotto (è una feature specifica del vecchio sistema, da confermare con il business se serve ancora), i log `error_log` con dati sensibili in chiaro.

## 6. Flusso lato modulo Magento 2

Stessa architettura, adattata alle convenzioni Magento:

1. **Payment method model**: implementare `\Magento\Payment\Model\Method\AbstractMethod` (o, meglio nelle versioni recenti, un metodo "offline"/redirect basato su `Magento\Payment\Gateway\*` con command pattern), `canOrder`, `canRefund` in base a cosa espone l'API.
2. **`config.xml` / `di.xml`**: campi di configurazione per `api_base_url`, `api_token` (salvato con `Magento\Framework\Encryption\EncryptorInterface`, non in chiaro), `webhook_secret`.
3. **Controller di redirect**: un controller `frontend` (`Kmoney/Checkout/Redirect`) che, in fase di `place order`, chiama `POST /api/v1/payment-requests` passando il totale ordine (convertito in centesimi interi) e l'`increment_id` dell'ordine come `external_reference`, poi reindirizza a `pay_url`. Ordine creato in stato `pending_payment`.
4. **Controller di ritorno** (`Kmoney/Checkout/Return`): verifica stato via `GET /payment-requests/{uuid}` prima di fatturare/invoice l'ordine.
5. **Webhook controller** (`Kmoney/Webhook/Index`, no ACL/CSRF perché è server-to-server, ma **con verifica firma HMAC obbligatoria**): su `payment_request.paid`, recupera l'ordine da `external_reference`, crea l'`invoice` e imposta lo stato `processing`.
6. **Valuta/importi**: Magento lavora in unità decimali (es. `19.99`), kmoney-app in centesimi interi — la conversione (`round($total * 100)`) va centralizzata in un unico helper per evitare errori di arrotondamento, esattamente il tipo di bug che `ky_to_cents()`/`ky_format()` prevengono lato kmoney-app.
7. **Rimborsi**: hook su `canRefund` / `refund()` che chiama l'endpoint di rimborso KMoney lato server (stesso discorso del punto 6 WooCommerce).

---

## 7. Sicurezza — checklist per entrambi i moduli

- Mai raccogliere o processare email/password KMoney del cliente sul dominio del negoziante. L'unica interazione con le credenziali del cliente avviene su `kmoney-app`, dopo il redirect.
- Bearer token del negoziante: salvato cifrato (WordPress: `wp_options` con autoload disattivato + cifratura applicativa, o meglio un secrets manager; Magento: `EncryptorInterface`). Mai loggato.
- `idempotency_key` sempre generato lato modulo e persistito insieme all'ordine, per gestire retry senza doppie richieste (segue la stessa regola già in vigore lato kmoney-app: "idempotency key obbligatorio, usare `Str::uuid()`" — il modulo deve rispettare la stessa disciplina anche se il campo lì si chiama diversamente).
- Verificare **sempre** la firma HMAC (`X-KMoney-Signature`) sui webhook in ingresso prima di fidarsi del payload; scartare richieste senza firma valida.
- Non completare mai un ordine solo sulla base del redirect del browser (`return_url`): il redirect è manipolabile dal cliente (basta cambiare l'URL). Va sempre confermato lato server con una chiamata `GET` allo stato della risorsa, o meglio ancora atteso il webhook.
- Timeout e retry: le chiamate verso `kmoney-app` vanno fatte con timeout ragionevole (10-30s, come fa già il plugin attuale) e gestione esplicita degli errori di rete — non assumere mai che "nessuna risposta" equivalga a "pagamento fallito" (rischio di doppio addebito su retry incauti).
- Rate limit lato kmoney-app: `60/min` generale, `10/min` su operazioni di scrittura — il modulo deve gestire il caso 429 con backoff, non ritentare a raffica.
- Log: evitare di loggare payload completi di richieste/risposte contenenti token o dati del cliente (il plugin attuale logga con `error_log` risposte API intere — pattern da non ripetere).

---

## 8. Riepilogo azioni

**Lato backend kmoney-app (prerequisito, team interno):**
1. Aggiungere `POST /api/v1/payment-requests` (creazione richiesta con `pay_url` di ritorno).
2. Aggiungere campo di correlazione (`external_reference`) e `return_url`/`cancel_url` al modello `PaymentRequest`.
3. Aggiungere evento webhook `payment_request.paid` a `Webhook::EVENTS`.
4. Agganciare `WebhookService::dispatch()` ai cambi di stato reali (oggi non è invocato in produzione, solo dal pulsante "Test").
5. (Opzionale ma consigliato) endpoint API per rimborso, se i moduli devono gestire resi self-service.

**Lato programmatori WooCommerce/Magento (una volta pronto il punto sopra):**
1. Costruire il gateway/payment method con redirect verso `pay_url`, niente form di credenziali.
2. Implementare receiver webhook con verifica HMAC.
3. Implementare verifica esplicita dello stato al ritorno cliente.
4. Gestire idempotenza, cifratura token, retry/backoff.
5. Non riportare la logica di split-percentuale per categoria prodotto del plugin attuale senza conferma esplicita del business — è una feature del sistema legacy, da valutare se ha ancora senso nel nuovo modello (probabilmente sì per KMoney, ma va ridisegnata contro l'API attuale, non quella di `kosmomoney.com`).
