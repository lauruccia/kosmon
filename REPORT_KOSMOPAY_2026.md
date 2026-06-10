# REPORT TECNICO E STRATEGICO — KosmoPay / KMoney
### Revisione completa: Sicurezza · Mobile-First · UX · Ledger · Roadmap
**Data analisi:** 10 giugno 2026 | **Analista:** Senior Full Stack + Security Auditor + UX Specialist

---

## 1. EXECUTIVE SUMMARY

KosmoPay è una piattaforma di circuito monetario complementare (stile Sardex) costruita su **Laravel 12 / PHP 8.2**, con valuta interna **KY** (importi in centesimi interi). Il progetto è tecnicamente ambizioso e strutturalmente solido nel nucleo finanziario, ma presenta criticità importanti su mobile, sicurezza secondaria e completezza funzionale che — se non risolte prima del lancio — possono generare frodi, perdita di fiducia e abbandono degli utenti.

**Giudizio sintetico:** ⭐⭐⭐½ / 5
- Il motore finanziario (`TransferBookingService`) è ben scritto, usa `lockForUpdate`, `forceFill+save`, idempotency key, partita doppia e audit log su ogni operazione.
- L'architettura è chiara, le route ben nominate, la PWA parzialmente implementata, il CSP presente.
- **Problemi critici aperti:** NFC HMAC legacy accettato senza downgrade obbligatorio, PIN di pagamento opzionale (non forzato per importi alti), nessun limite singolo trasferimento globale di default, API `store` che recupera l'utente `initiator` in modo fragile, `CheckBalanceAlerts` che usa `available_balance` correttamente ma senza lock, cashback che chiama ricorsivamente `book()` (rischio loop se CashbackRule target mal configurato).
- **Test:** Suite Feature ampia (50+ test), ma mancano test di concorrenza, test PIN/WebAuthn integrati, test IDOR e test PWA.

---

## 2. GIUDIZIO COMPLESSIVO SUL PROGETTO

| Area | Voto | Note |
|---|---|---|
| Architettura backend | 8/10 | Laravel 12, servizi separati, DI, lockForUpdate |
| Ledger / integrità finanziaria | 8/10 | Partita doppia, idempotency, audit log |
| Sicurezza applicativa | 6/10 | CSP presente, 2FA ok, ma PIN opzionale, NFC legacy, IDOR parziali |
| Mobile UX | 6/10 | Bottom nav presente, wizard invia ok, ma dashboard pesante, molte tabelle |
| PWA | 7/10 | SW, manifest, push — manca install prompt, offline incompleto |
| Test coverage | 6/10 | Ampia ma mancano concorrenza, IDOR, PIN, PWA |
| Performance | 6/10 | Cache KPI ok, ma N+1 in alcune liste, indici da verificare |
| Nuove funzionalità | 4/10 | Referral abbozzato, directory presente, mancano mappa, missioni, marketplace |
| Pronto per produzione | 6.5/10 | Usabile con fix critici — non lanciare senza i 7 fix immediati |

---

## 3. MAPPA TECNICA DEL PROGETTO

### Stack reale
- **PHP** 8.2+ · **Laravel** 12 · **Node** ^22 · **Vite** 7 · **Tailwind CSS** v4
- **DB dev:** SQLite · **DB prod:** MySQL (con indici performance già in migration `2026_05_26`)
- **Queue/Cache/Session:** Database (dev) → Redis (prod)
- **WebSocket:** Laravel Reverb + Laravel Echo + pusher-js
- **Auth:** Email/Password + WebAuthn/Passkey (`web-auth/webauthn-lib` v4.9) + 2FA TOTP
- **PDF:** barryvdh/laravel-dompdf · **QR:** simplesoftwareio/simple-qrcode
- **Push:** minishlink/web-push 9.0 · **Pagamenti fiat:** Stripe
- **Monitoring:** Sentry (opzionale, attivato solo se DSN presente)
- **PWA:** manifest.json + sw.js con Payment Handler API

### Struttura moduli principali

```
Portal (utenti)       → PortalController, SendPaymentController, WalletController
Pagamenti QR          → IncassoQrController, PaymentRequestController
Pagamenti NFC         → NfcPaymentController, NfcCardPaymentController, StaticNfcController
Pagamenti Sonic       → SonicPaymentController
Pagamenti Codice      → CodePaymentController
W3C Payment Handler   → PaymentHandlerController + sw.js (paymentrequest event)
Link pagamento        → PaymentLinkController
Invio mobile-first    → SendPaymentController (wizard 3-step)
Ledger / motore KY    → TransferBookingService (UNICO punto di scrittura saldi)
Fee                   → TransactionFee model + bookFee() in TransferBookingService
Cashback              → CashbackService (post-booking, fire-and-forget)
Netting               → NettingService, NettingController
Piani rateali         → PaymentPlanService, PaymentPlanController
Pagamenti programmati → ScheduledPaymentService, ProcessScheduledPayments job
KYC                   → KycController, KycDocument model
Contratto             → ContractController (firma OTP)
Onboarding            → OnboardingController (3 step: profilo, KYC, attesa)
Admin                 → AdminController (dashboard, KYC, sospensione, emissione KY)
Broker                → BrokerController
API v1                → Api/V1/TransferController, AccountController, PaymentPlanController
Webhook               → WebhookService, SendWebhookJob
Notifiche             → Laravel Notifications (mail+database+broadcast+webpush)
Ricarica fiat         → KyCardController (Stripe + PayPal + Bonifico)
NFC card fisica       → NfcCardController, NfcCardPaymentController, AdminNfcCardController
Referral              → ReferralController
Report merchant       → MerchantReportController
Kit merchant          → MerchantKitController (QR PDF stampabile)
```

---

## 4. STATO ATTUALE FUNZIONALITÀ

| Modulo | Stato | Note |
|---|---|---|
| Login / Register | ✅ Completo | Email+password, throttle, verified |
| WebAuthn / Passkey | ✅ Completo | web-auth/webauthn-lib v4.9, login+register |
| 2FA TOTP | ✅ Completo | Setup, challenge, recovery codes |
| Step-up auth | ✅ Completo | Per azioni sensibili |
| Onboarding wizard | ✅ Completo | 3 step, KYC upload |
| Contratto firma OTP | ✅ Completo | Firma digitale con OTP |
| Dashboard utente | ✅ Completo | KPI, trend, saldo, pending requests |
| Invio KY (mobile-first) | ✅ Completo | Wizard 3-step, PIN, beneficiari, ricerca |
| Ricezione / Incasso | ✅ Completo | QR dinamico, NFC, Sonic, Codice |
| QR statico merchant | ✅ Completo | `/paga/{kyAccountNumber}` |
| Link pagamento | ✅ Completo | Creazione, condivisione, annullamento |
| NFC card fisica | ✅ Completo | Identify, request, authorize con PIN |
| Sonic payment | ✅ Completo | Web Audio API |
| W3C Payment Handler | ✅ Completo | SW + handler window |
| Storico movimenti | ✅ Completo | Filtri, CSV export |
| Dettaglio transazione | ✅ Completo | Ricevuta, PDF |
| Rimborso merchant | ✅ Completo | Parziale o totale |
| Nota di credito | ✅ Completo | Libera o collegata a transfer |
| Piano rateale | ✅ Completo | Creazione, approvazione, rate auto |
| Netting | ✅ Completo | Compensazione incrociata |
| Pagamenti programmati | ✅ Completo | Frequenza configurabile |
| Richiesta testo | ✅ Completo | Richiesta formale con approvazione |
| Estratto conto PDF | ✅ Completo | Download con dompdf |
| Wallet / Carta virtuale | ✅ Completo | PDF carta, blocco/sblocco |
| KYC admin | ✅ Completo | Approve/reject/request-docs |
| Sospensione azienda | ✅ Completo | EnsureCompanyNotSuspended middleware |
| Fido / CreditLimit | ✅ Completo | Admin set, richiesta utente |
| Limiti giornalieri/mensili | ✅ Completo | Per account e per utente |
| Cashback | ✅ Completo | CashbackRule configurabile per tipo/target |
| Fee transazioni | ✅ Completo | TransactionFee model, bookFee() |
| API v1 | ✅ Completo | GET balance/transfers, POST transfer |
| Webhook | ✅ Completo | Delivery, retry, log |
| API token | ✅ Completo | Abilities read/write, step-up |
| Sub-account / delegati | ✅ Completo | Invito, budget, limiti |
| Web Push | ✅ Completo | Subscribe, VAPID, notifiche |
| Ricarica Stripe | ✅ Completo | KyCard, checkout, webhook |
| Ricarica PayPal | ✅ Completo | Create order, capture |
| Ricarica Bonifico | ⚠️ Parziale | Conferma manuale admin |
| Directory aziende | ✅ Completo | Filtri, settori |
| Shop / annunci | ✅ Completo | Listing con immagini, % KY |
| KIT merchant | ✅ Completo | QR PDF, NFC sticker |
| Referral | ⚠️ Parziale | Codice presente, bonus NON implementato |
| Mappa attività | ❌ Mancante | No coordinate geografiche, no mappa |
| Missioni settimanali | ❌ Mancante | — |
| Badge / livelli | ❌ Mancante | — |
| Bonus benvenuto | ❌ Mancante | KYC approvato → nessun credito automatico |
| Plugin WooCommerce | ❌ Mancante | — |
| Marketplace / offerte | ❌ Mancante | — |
| Dark mode | ✅ Completo | CSS variables + localStorage |
| PWA installabile | ⚠️ Parziale | Manifest ok, manca install prompt JS |

---

## 5. ANALISI MOBILE-FIRST DETTAGLIATA

### Struttura responsive attuale
Il layout usa una **sidebar sinistra** (desktop) che collassa in **bottom navigation fissa** su mobile (`@media max-width: 720px`). La bottom nav è presente nel layout (`mobile-bottom-nav`) con voce Home, Movimenti, QR, Profilo. Il body riceve la classe `has-bottom-nav` per gli utenti non-backoffice.

Il design system usa CSS custom properties con dark/light mode. Font: `Aptos / Segoe UI / system-ui`.

### Tabella problemi mobile per pagina

| Pagina | File | Problema | Gravità | Fix |
|---|---|---|---|---|
| **Dashboard** | `portal/dashboard.blade.php` | KPI cards a 2-col su 360px: overflow orizzontale possibile; trend percentuali troppo dense | MEDIA | Stacked layout <400px, nascondere trend su schermi piccoli |
| **Dashboard** | stessa | Grafico trend mensile (canvas) non scalato per 320px | MEDIA | `max-width:100%; height:auto` forzato |
| **Dashboard** | stessa | Tabella ultimi movimenti non trasformata in card su mobile | ALTA | Convertire `<table>` in card list con flex |
| **Invia KY** | `portal/invia.blade.php` | ✅ Ben fatto: wizard 3-step, quick amounts 4-col, tastierino grande | OK | — |
| **Movimenti** | `portal/movements.blade.php` | Tabella con 8+ colonne: taglio orizzontale su 360px | ALTA | Card list mobile, hide colonne secondarie |
| **Dettaglio transfer** | `portal/transfer-detail.blade.php` | Form rimborso e pulsanti ravvicinati su small screen | MEDIA | Stack verticale, bottoni full-width |
| **QR Incasso** | `portal/incasso-qr-form.blade.php` | QR code centrato ma testo sotto piccolo (11px) | BASSA | Font-size min 14px |
| **NFC Authorize** | `portal/nfc-cards/authorize.blade.php` | PIN input 6-digit ok, ma confirm button non full-width | MEDIA | btn full-width su <480px |
| **Login** | `auth/login.blade.php` | Input email/password corretti, ma Passkey button scomparso su 320px | MEDIA | Flex-wrap o stack verticale |
| **Onboarding** | `portal/benvenuto/*` | Step upload KYC: area drop file non funziona bene su iOS (no drag) | ALTA | Input file fallback prominente su mobile |
| **Wallet** | `portal/wallet.blade.php` | Carta virtuale PNG a larghezza fissa (340px): overflow su 320px | MEDIA | `max-width:100%; width:auto` |
| **KYC** | `portal/kyc.blade.php` | Lista documenti uploadati: no thumbnail su mobile | BASSA | Aggiungere preview thumbnail |
| **Admin dashboard** | `admin/*` | Tabelle admin: nessun adattamento mobile — solo desktop | ALTA per tablet | Scrollable-x con sticky prima colonna |
| **Profilo** | `portal/profile-edit.blade.php` | Form a 2 colonne: collassa bene ma label troppo piccole (11px) | BASSA | Min 13px |
| **Estratto conto** | `portal/statement.blade.php` | Date picker non ottimizzato per tastiera mobile | MEDIA | Native `<input type="month">` |
| **Notifiche** | `portal/notifications.blade.php` | Lista densa, nessun swipe-to-dismiss | BASSA | Touch target min 48px |
| **Pay QR** | `portal/pay-qr.blade.php` | Scanner QR usa getUserMedia: banner permesso non gestito | ALTA | Gestire errore permesso camera con messaggio chiaro |

### UX mobile ideale (già parzialmente implementata)

**Cosa già esiste:**
- ✅ Bottom navigation fissa (4 voci)
- ✅ Wizard invio 3-step con quick amounts e ricerca destinatario
- ✅ Hero saldo nella dashboard
- ✅ Pending requests visibili in dashboard
- ✅ QR scanner alla route `/scanner`

**Cosa manca o va migliorato:**
- ❌ Pulsanti INVIA KY / RICEVI KY non abbastanza prominenti in dashboard (sepolti dopo KPI)
- ❌ Nessun floating action button (FAB) per azione rapida
- ❌ Tabelle non convertite in card
- ❌ Nessun condividi-su-WhatsApp diretto dalla ricevuta
- ❌ Nessun feedback haptic (vibration API) dopo pagamento
- ❌ Onboarding KYC non ottimizzato per fotocamera mobile

---

## 6. NUOVO FLUSSO IDEALE INVIO/RICEZIONE KMONEY

### Flusso attuale (SendPaymentController)
```
GET /invia → wizard step 1 (ricerca destinatario)
→ step 2 (importo + quick amounts)
→ step 3 (riepilogo + PIN se importo ≥ soglia)
→ POST /invia/esegui → redirect /invia/ricevuta/{uuid}
```
**Stato:** Già implementato e ben strutturato. Il wizard è mobile-first, ha ricerca AJAX, beneficiari salvati, quick amounts, PIN condizionale.

### Gap rispetto al flusso ideale

| Funzionalità | Stato | Note |
|---|---|---|
| Ricerca per nome/email/telefono/KY | ✅ | Presente in `SendPaymentController::search()` |
| Destinatari recenti / frequenti | ✅ | Presenti nella pagina `/invia` |
| Quick amounts (5/10/20/50 KY) | ✅ | Grid 4-col presenti |
| PIN a 6 cifre | ✅ | `PaymentPin::verify()`, rate limiting |
| Conferma con riepilogo | ✅ | Step 3 del wizard |
| Notifica push al destinatario | ✅ | `PaymentReceivedNotification` |
| Ricevuta immediata | ✅ | `/invia/ricevuta/{uuid}` |
| Condivisione WhatsApp ricevuta | ❌ | Mancante — aggiungere link `wa.me` |
| Conferma WebAuthn/biometrica | ❌ | Solo PIN, non WebAuthn sul pagamento |
| QR personale statico per ricevere | ✅ | `/paga/{kyAccountNumber}` + Kit merchant |
| Link personale condivisibile | ✅ | PaymentLink + link `/pay/{token}` |
| Richiesta KMoney (pull) | ✅ | TextPaymentRequest + IncassoQr |
| Annullamento entro 30 sec | ❌ | Non implementato (legalmente complesso) |
| Alert primo destinatario nuovo | ✅ | `is_first` flag in `recipientInfo()` |

### Modifiche consigliate al flusso

**Frontend (invia.blade.php):**
1. Aggiungere bottone "Condividi su WhatsApp" nella pagina ricevuta con link `https://wa.me/?text=Ho+inviato+X+KY+a+...`
2. Aggiungere `navigator.vibrate([100,50,100])` dopo pagamento confermato
3. Step 3 riepilogo: mostrare foto/logo destinatario più grande (64px → 80px)
4. Aggiungere opzione "Usa WebAuthn invece del PIN" se credenziali disponibili

**Backend:**
1. `SendPaymentController::execute()`: aggiungere opzione di conferma via WebAuthn assertion (nuovo endpoint `/invia/esegui-webauthn`)
2. Aggiungere campo `share_url` nella ricevuta per composizione link WhatsApp

---

## 7. ANALISI SICUREZZA COMPLETA

### Tabella vulnerabilità

| # | Vulnerabilità | Gravità | File | Funzione/Area | Evidenza | Rischio concreto | Fix | Priorità |
|---|---|---|---|---|---|---|---|---|
| S-01 | **NFC HMAC legacy (16 hex) ancora accettato** | 7/10 | `NfcCard.php` | `verifyHmac()` | `if (strlen($sig) === 16 && hash_equals(substr($expected, 0, 16), $sig))` — accetta firme dimezzate | Attacker con 16 char bruta-força più facilmente; card non ancora riprogrammate sono vulnerabili | Impostare deadline per disabilitare il fallback; loggare card legacy e notificare admin | ALTA |
| S-02 | **PIN pagamento opzionale** | 7/10 | `SendPaymentController.php` | `execute()` | Il PIN è richiesto solo se `$hasPin && $pinThreshold !== null && $amountCents >= pinThreshold`; se l'utente non ha impostato PIN, nessuna conferma secondaria | Sessione rubata o dispositivo condiviso → pagamenti senza conferma | Rendere obbligatorio PIN (o WebAuthn) per importi sopra soglia, con wizard di setup forzato al primo pagamento | ALTA |
| S-03 | **Nessun limite singolo trasferimento di default globale** | 6/10 | `TransferBookingService.php` | `assertTransferWithinLimits()` | `$singleTransferLimit = $limits['per_movement_limit'] ?? $account->spending_limit ?? $creditLimit?->single_transfer_limit` — tutti nullable, nessun default hard-coded | Utente senza limiti configurati può svuotare l'intero saldo in un colpo | Aggiungere default hard-coded (es. 200.000 centesimi = 2000 KY) come fallback finale | ALTA |
| S-04 | **API TransferController: initiator recuperato in modo fragile** | 6/10 | `Api/V1/TransferController.php` | `store()` | `$initiator = $fromAccount->ownerUser ?? $fromAccount->company?->users()->first()` — il primo utente della company diventa initiator | Se la company ha utenti sospesi/non autorizzati come primo record, il transfer viene attribuito a loro; inconsistenza audit | Usare utente associato al token (`$token->creator`) come initiator, o creare utente sistema per API | ALTA |
| S-05 | **IDOR su sub_account_id nei movimenti** | 5/10 | `PortalController.php` | `movements()` | Filtro `sub_account_id` letto da query string senza verifica che il sub_account appartenga al currentAccount | Utente A può vedere movimenti del sub_account di Utente B | Aggiungere `->where('parent_account_id', $currentAccount->id)` nel resolver | ALTA |
| S-06 | **Health check espone info queue failures senza token** | 4/10 | `web.php` | `/health` route | Senza token risponde solo `{status, timestamp}` — OK. Con token mostra failed jobs | Già corretto con token gate — rischio basso, ma verificare che `services.health.token` sia impostato in prod | Documentare variabile obbligatoria; alert se mancante | MEDIA |
| S-07 | **CSP con `unsafe-inline` su script-src** | 5/10 | `ContentSecurityPolicy.php` | `buildPolicy()` | `script-src 'self' 'unsafe-inline' https://js.stripe.com` | XSS parzialmente mitigato ma `unsafe-inline` vanifica protezione XSS reflessa | Migrare a nonce-based CSP: `'nonce-{random}'` per ogni response; rimuovere `unsafe-inline` | MEDIA |
| S-08 | **Cashback può chiamare `book()` che chiama `applyIfEligible()` ricorsivamente** | 5/10 | `CashbackService.php` | `applyIfEligible()` | Kind `portal_cashback` escluso esplicitamente, ma se regola mal configurata con kind catch-all potrebbe rientrare | Loop infinito → stack overflow o DoS del queue worker | Aggiungere guard `in_array($transfer->kind, CASHBACK_EXEMPT_KINDS)` più robusto; aggiungere test dedicato | MEDIA |
| S-09 | **Session timeout non configurato per mobile** | 4/10 | `config/session.php` | `lifetime` | Default Laravel 120 min; su mobile una sessione aperta può essere usata da terzi | Furto sessione su dispositivo non presidiato | Configurare `SESSION_LIFETIME=30` in prod; aggiungere "logout automatico dopo X minuti di inattività" opzionale | MEDIA |
| S-10 | **NfcCardPaymentController::authorize non verifica che l'account merchant sia titolare corretto** | 5/10 | `NfcCardPaymentController.php` | `authorize()` | `$merchantAccount = $session->merchant_account_id ? Account::findOrFail(...) : Account::where('company_id', $session->merchant_company_id)->first()` — nessuna verifica che il merchant loggato corrisponda | Merchant A potrebbe completare pagamento su sessione creata da Merchant B se intercetta il nonce | Aggiungere `abort_unless($merchantAccount->id === $resolvedMerchant->id, 403)` | ALTA |
| S-11 | **Webhook HMAC non verificato alla ricezione** | 3/10 | `WebhookService.php` | outbound | Webhook inviati firmati con HMAC; ma il sistema non riceve webhook da terzi — rischio basso | — | Documentare che KMoney è solo sender, non receiver | BASSA |
| S-12 | **APP_DEBUG non verificato a runtime** | 3/10 | `config/app.php` | — | Se `APP_DEBUG=true` in prod, stack trace esposto | Espone struttura interna, percorsi file, config | Aggiungere check in `AppServiceProvider::boot()`: `abort_if(config('app.debug') && app()->environment('production'), 500)` | MEDIA |

### Top 10 problemi sicurezza (in ordine di priorità)

1. **[S-02] PIN opzionale** — sessione rubata = pagamenti liberi
2. **[S-01] NFC HMAC legacy 16-char** — firma debole ancora accettata
3. **[S-03] Nessun limite singolo default** — svuotamento saldo illimitato
4. **[S-04] API initiator fragile** — audit trail inconsistente
5. **[S-10] NFC authorize merchant check** — nonce intercettabile
6. **[S-05] IDOR sub_account movimenti** — visibilità trasferimenti altrui
7. **[S-07] CSP unsafe-inline** — XSS reflessa parzialmente aperta
8. **[S-08] Cashback loop risk** — potenziale DoS queue
9. **[S-09] Session timeout mobile** — sessione aperta lunga
10. **[S-06] Health token non obbligatorio** — info minime esposte senza config

### Fix immediati (entro 7 giorni)

```
1. S-02: Wizard setup PIN obbligatorio al primo accesso (o WebAuthn)
2. S-03: Aggiungere DEFAULT_SINGLE_TRANSFER_LIMIT=200000 in SystemSetting e usarlo come ultimo fallback
3. S-04: API TransferController → usare $token->creator come initiator
4. S-10: NfcCardPaymentController::authorize → verifica merchant_account_id contro user loggato
5. S-05: PortalController::movements → filtrare sub_account_id con ->where('parent_account_id', $currentAccount->id)
```

### Fix entro 30 giorni

```
6. S-01: Programmare deprecazione HMAC legacy (log + notifica admin quante card legacy attive)
7. S-07: Migrare CSP a nonce-based (Laravel middleware con nonce iniettato nel layout)
8. S-09: SESSION_LIFETIME=30 in .env.example prod + UI "rimani connesso"
9. S-12: Check APP_DEBUG in AppServiceProvider
10. Aggiungere WebAuthn come alternativa al PIN nel flusso invia
```

### Fix entro 60 giorni

```
11. S-01: Disabilitare definitivamente HMAC legacy dopo riprogrammazione card
12. Implementare Content-Security-Policy-Report-Only → poi enforce
13. Aggiungere CORS policy esplicita per API (attualmente default Laravel)
14. Aggiungere header X-Content-Type-Options, X-Frame-Options, Referrer-Policy
```

---

## 8. ANALISI LEDGER / SALDI / TRANSAZIONI

### Punti solidi

- **Partita doppia reale:** ogni `book()` crea esattamente 2 `LedgerEntry` (debit + credit) con `balance_after` calcolato.
- **`lockForUpdate()` su tutti gli account** dentro `DB::transaction()` — protezione da race condition e double-spend.
- **`forceFill(['available_balance' => ...])->save()`** ovunque — nessun update diretto non controllato trovato nel codice analizzato.
- **Idempotency key obbligatoria** con guard `Transfer::where('idempotency_key', $key)->first()` prima di ogni booking.
- **Fee separata** come transfer `portal_fee` con `related_transfer_id`, proprio ledger, propria idempotency `fee_{uuid}`.
- **Cashback** è `portal_cashback` escluso dal ciclo cashback — loop prevenuto per kind.
- **Audit log** su ogni evento finanziario (booked, rejected, requested, confirmed, refund, credit_note).
- **Tentativi falliti loggati** con `recordRejectedAttempt()` + blocco automatico dopo 3 fallimenti in 5 min.
- **Test** `TransferBookingTest` verifica ledger bilanciato, idempotency, limiti, credit limit.

### Rischi e bug

| # | Problema | File | Dettaglio | Gravità |
|---|---|---|---|---|
| L-01 | **`confirmRequest()` non chiama `bookFee()`** | `TransferBookingService.php` | Solo `bookSettledTransfer()` applica fee; se una collection request viene confermata, nessuna fee viene addebitata | MEDIA |
| L-02 | **`confirmRequest()` non chiama `CashbackService`** | `TransferBookingService.php` | Stesso problema: cashback non erogato su richieste di incasso confermate | MEDIA |
| L-03 | **`refundMerchant()` non verifica fido/saldoDisponibile del merchant** | `TransferBookingService.php` | Usa `available_balance - refundAmount` direttamente; se merchant è in fido, può portare il saldo più sotto del massimale | BASSA |
| L-04 | **`CheckBalanceAlerts` usa `available_balance` senza lock** | `CheckBalanceAlerts.php` | Legge saldo senza transaction/lock — può generare notifiche false positive/negative in condizioni di concorrenza | BASSA |
| L-05 | **CashbackService usa `$this->booking->book()` con `systemUser`** | `CashbackService.php` | `systemUser = systemAccount->ownerUser ?? company->users()->first()` — fragile come S-04; se il conto sistema non ha owner, cashback silently fails | MEDIA |
| L-06 | **Nessun test di concorrenza** | `tests/Feature/` | Mancano test con `DB::transaction()` paralleli per verificare che lockForUpdate prevenga double-spend | ALTA |

### Test automatici da aggiungere (ledger)

```php
// Concorrenza: 2 thread simultanei che pagano dallo stesso conto
// Verificare che solo uno vada a buon fine se saldo insufficiente per entrambi

// Idempotency: stesso idempotency_key inviato 2 volte → stesso transfer restituito

// Fee: ogni book() con fee configurata → verificare 2+2 LedgerEntry (transfer + fee)

// Cashback: book() con regola cashback → verificare credito sul from_account

// confirmRequest: verificare fee e cashback applicati anche su richieste confermate
```

---

## 9. ANALISI PERFORMANCE

| Problema | File | Query/Area | Impatto | Soluzione | Priorità |
|---|---|---|---|---|---|
| **Dashboard: 8+ query separate non aggregate** | `PortalController::dashboard()` | `income30, expense30, incomePrev, expensePrev, kyCardCount, kyCardTotalKy` — 6 query anche se in cache 5 min | BASSO (cache presente) | Verificare che cache sia attiva in prod; aumentare TTL a 10 min | BASSA |
| **Dashboard: `pendingIncomingRequests` senza limit** | `PortalController::dashboard()` | `Transfer::where(...)->get()` senza paginazione | MEDIO se molte richieste pending | Aggiungere `->limit(20)` | MEDIA |
| **`recentRecipients` in invia.blade** | `SendPaymentController::show()` | `Transfer::where(...)->get()` senza indice su `from_account_id + status + booked_at` | MEDIO su grandi tabelle | Indice composito: `(from_account_id, status, booked_at)` |  ALTA |
| **`spentToday()` / `spentThisMonth()` senza cache** | `Account.php` | Chiamate in `assertTransferWithinLimits()` dentro ogni booking | ALTO su traffico elevato | Cache con TTL 60s per chiave `spent_today_{account_id}` | ALTA |
| **N+1 in lista movimenti admin** | `AdminController::transfers()` | Eager loading da verificare | MEDIO | Aggiungere `->with(['fromAccount.company', 'toAccount.company'])` se mancante | MEDIA |
| **`checkLimits()` NfcCard: `refresh()` dentro transazione** | `NfcCard.php` | `$this->refresh()` dopo `refreshSpentCounters()` — 2 query extra in transazione | BASSO | Unire in una sola query con `fresh()` | BASSA |
| **`CashbackRule::where('is_active', true)->get()`** | `CashbackService.php` | Carica tutte le regole attive in memoria per ogni pagamento | MEDIO se molte regole | Cache con tag `cashback_rules`, invalidata su modifica | MEDIA |
| **Migration indici MySQL** | `2026_05_26_100000` | Indici su `transfers(from_account_id, status, booked_at)` già presenti | OK | Verificare con `EXPLAIN` in prod | — |

---

## 10. ANALISI PWA

### Cosa esiste
- ✅ `public/manifest.json`: `display: standalone`, icone 192+512, shortcuts (Paga/Movimenti/Wallet), `theme_color`, `lang: it`
- ✅ `public/sw.js`: Cache-first per asset, network-first per HTML, push notifications, Payment Handler API
- ✅ `layouts/portal.blade.php`: `<link rel="manifest">`, `apple-mobile-web-app-capable`, apple-touch-icon, `theme-color`
- ✅ Web Push: `PushSubscriptionController`, VAPID, `WebPushService`, `SendWebPushAfterNotification` listener
- ✅ Offline fallback: `/offline.html` in precache

### Cosa manca / da migliorare

| Item | Stato | Priorità |
|---|---|---|
| **Install prompt JS** (`beforeinstallprompt`) | ❌ Mancante | ALTA — senza di esso su Android non appare il banner "Installa" |
| **Icone separate per `any` e `maskable`** | ⚠️ Stesso file | MEDIA — usare icona con padding per maskable |
| **`display_override`** | ✅ Presente | — |
| **Offline page localizzata** | ⚠️ `/offline.html` generico | BASSA |
| **Background sync** per pagamenti offline | ❌ Mancante | BASSA (complesso, richiede spec) |
| **Periodic background sync** (balance refresh) | ❌ Mancante | BASSA |
| **iOS: status bar color** | ✅ `black-translucent` | — |
| **SW bypass per pagine dinamiche** | ✅ Lista BYPASS_PATTERNS ampia | — |
| **Critical CSS inline** | ❌ Mancante | MEDIA — Tailwind genera tutto via Vite |
| **Lighthouse mobile score** | Non misurato | ALTA — misurare e portare ≥90 |

### Roadmap PWA

**Settimana 1:** Aggiungere install prompt JS nel layout:
```javascript
let deferredPrompt;
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault(); deferredPrompt = e;
    document.getElementById('btn-install-pwa')?.classList.remove('hidden');
});
```

**Settimana 2:** Separare icone `any` e `maskable` con padding corretto.

**Settimana 3:** Misurare Lighthouse mobile; ottimizzare critical CSS e lazy loading immagini.

**Settimana 4:** Aggiungere banner "Scarica l'app" nella dashboard per utenti mobile non-installati.

---

## 11. NUOVE FUNZIONALITÀ CONSIGLIATE

| # | Funzionalità | Perché serve | Impatto | Complessità | Priorità |
|---|---|---|---|---|---|
| F-01 | **Bonus benvenuto automatico** (es. 10 KY dopo KYC approvato) | Incentiva completamento KYC e prima transazione | ALTO (acquisizione) | BASSA — hook su `KycController::approve()` | CRITICA |
| F-02 | **Referral program completo** (codice già presente, bonus mancante) | Crescita virale — ogni utente attiva un amico | ALTO | BASSA — aggiungere trigger in `register()` | ALTA |
| F-03 | **Condivisione WhatsApp dalla ricevuta** | Viralità organica del circuito | ALTO | BASSISSIMA — link `wa.me` | CRITICA |
| F-04 | **Install prompt PWA** | Aumenta retention — app installata usata 3x di più | ALTO | BASSA | ALTA |
| F-05 | **Mappa attività** (leaflet.js + coordinate su Company) | Utenti trovano dove spendere KY vicino a loro | ALTO | MEDIA — aggiungere lat/lng a companies, mappa leaflet | ALTA |
| F-06 | **Missioni settimanali** (es. "Fai 3 pagamenti questa settimana") | Gamification → uso frequente | MEDIO | MEDIA — nuova tabella `missions`, job verifica | MEDIA |
| F-07 | **Badge e livelli utente** | Fidelizzazione visiva | MEDIO | MEDIA | MEDIA |
| F-08 | **Promozioni merchant** (sconti % in KY per un periodo) | Stimola vendite nel circuito | ALTO | MEDIA — extend Listing con `promotion_rate` | ALTA |
| F-09 | **Statistiche velocity moneta** in admin | Admin capisce circolazione reale | MEDIO | BASSA — query aggregate già disponibili | MEDIA |
| F-10 | **Pagina pubblica merchant** `/m/{slug}` | Link condivisibile con "Paga in KY" | ALTO | BASSA — estendere company-show public | ALTA |
| F-11 | **QR personale statico su dashboard** | Utente mostra QR per ricevere istantaneamente | ALTO | BASSISSIMA — già `/paga/{kyAccountNumber}` | CRITICA |
| F-12 | **Notifiche intelligenti push** (es. "Hai 50 KY da spendere, ecco 3 attività vicino a te") | Stimolo uso attivo | MEDIO | MEDIA — richiede mappa | MEDIA |
| F-13 | **Escrow / pagamento condizionato** | Fiducia nelle transazioni tra sconosciuti | ALTO (commerciale) | ALTA | BASSA |
| F-14 | **Plugin WooCommerce** | Merchant e-commerce accetta KY online | ALTO | ALTA | MEDIA |
| F-15 | **Report circolazione KY** per admin | Trasparenza e governance del circuito | MEDIO | BASSA | MEDIA |

---

## 12. ANALISI LEGAL / GDPR (ORIENTATIVA — non consulenza legale)

> ⚠️ Queste note sono spunti tecnici da sottoporre a un legale specializzato in fintech e GDPR.

### Checklist

| Area | Stato | Rischio | Azione |
|---|---|---|---|
| **Natura circuito chiuso** | ✅ KY non convertibile in EUR | MEDIO — verificare che non ricada in e-money regulation (Dir. 2009/110/CE) | Parere legale obbligatorio |
| **AML / KYC** | ✅ Documenti KYC richiesti, approvazione admin | MEDIO — soglie AML da definire | Definire soglie (es. >1000 KY/mese = enhanced KYC) |
| **GDPR — Privacy Policy** | ✅ Route presente (`/privacy`) | — | Verificare completezza (base legale, retention, trasferimenti extra-UE) |
| **GDPR — Data retention** | ❌ Nessuna policy tecnica | ALTO — trasferimenti e log devono avere retention definita | Implementare cleanup automatico dopo N anni |
| **GDPR — Cancellazione utente** | ❌ Nessuna route "elimina account" | ALTO — diritto all'oblio art. 17 GDPR | Aggiungere `DELETE /profilo/account` con anonimizzazione |
| **GDPR — Data breach** | ⚠️ Sentry presente | MEDIO — procedura di notifica entro 72h da definire | Redigere procedura interna |
| **Contratto di adesione** | ✅ Firma OTP con PDF | — | Verificare che PDF firmato sia archiviato immutabilmente |
| **Documenti KYC** | ✅ Upload su storage | ALTO — accesso limitato, crittografia a riposo | Verificare che disco prod sia encrypted; accesso solo admin |
| **Gestione reclami** | ✅ Route `/legale/reclami` presente | — | Verificare che procedura sia operativa e documentata |
| **Fiscalità aziende** | ❌ Non gestita | MEDIO — KY ricevuti = fatturazione? | Consulente fiscale obbligatorio |
| **Responsabilità pagamenti errati** | ⚠️ Rimborso manual | MEDIO | Definire SLA rimborso e policy storni |
| **Cookie policy** | ✅ Route presente | — | Verificare che cookie banner sia compliant (no dark pattern) |
| **Log e conservazione** | ✅ AuditLog presente | — | Definire retention (min 5 anni per obblighi fiscali) |

### Domande prioritarie per legale/commercialista
1. KMoney rientra nella definizione di moneta elettronica ex Dir. 2009/110/CE?
2. Quali obblighi AML si applicano a questo circuito chiuso?
3. Come si classifica fiscalmente il ricevere KY per un'azienda aderente?
4. Il contratto OTP è sufficiente come firma digitale ai sensi del CAD?
5. È necessario il registro dei trattamenti GDPR (art. 30)?

---

## 13. BUG CRITICI TROVATI

| # | Bug | File | Descrizione | Impatto |
|---|---|---|---|---|
| B-01 | **Fee non applicata su `confirmRequest()`** | `TransferBookingService.php` | Solo `bookSettledTransfer()` chiama `bookFee()`; le collection request confermate non generano fee | Perdita di revenue per l'admin |
| B-02 | **Cashback non applicato su `confirmRequest()`** | `TransferBookingService.php` | Stesso motivo: `applyIfEligible()` non chiamato | Utente non riceve cashback su incassi confermati |
| B-03 | **`CashbackService` senza fallback utente sistema robusto** | `CashbackService.php` | `systemUser = systemAccount->ownerUser ?? company->users()->first()` — se company ha utenti in ordine casuale, il primo potrebbe non avere permessi | Cashback attribuito a utente sbagliato in audit log |
| B-04 | **`recentRecipients` in `/invia` include transfers non outgoing** | `SendPaymentController::show()` | Query `Transfer::where('from_account_id', ...)` non filtra `portal_fee` e `portal_cashback` — destinatari "sistema" possono comparire nella lista | UX confusa: conto sistema nelle scelte rapide |
| B-05 | **`Account::hasKyAccountNumber` regex non corrisponde a tutti i formati** | `Account.php` | Regex `/^KY[A-Z0-9]{14}$/` non matcha `KYB...` (16 char totali) e `KYP...` (16 char) — solo `KY` + 14 char | Numero di conto business/privato non validato correttamente |
| B-06 | **Scheduler `queue:work` in `console.php`** | `routes/console.php` | `Schedule::command('queue:work --stop-when-empty')` lanciato ogni minuto via cron — su hosting senza supervisor può creare processi zombie | Worker multipli sovrapposti in prod senza Redis |

### Fix B-04 (immediato)
```php
// In SendPaymentController::show()
$recentRecipients = Transfer::where('from_account_id', $currentAccount->id)
    ->where('status', 'booked')
    ->whereNotIn('kind', ['portal_fee', 'portal_cashback', 'portal_credit_note'])  // aggiungere
    ->with('toAccount')
    ...
```

### Fix B-05 (immediato)
```php
// Account.php — regex corretta
public static function hasKyAccountNumber(?string $value): bool
{
    return is_string($value) && preg_match('/^KY[BP]?[A-Z0-9]{13,14}$/', $value) === 1;
}
```

---

## 14. VULNERABILITÀ TROVATE (sintesi — dettaglio in sezione 7)

| ID | Titolo | Gravità |
|---|---|---|
| S-01 | NFC HMAC legacy 16-char accettato | 7/10 |
| S-02 | PIN pagamento opzionale | 7/10 |
| S-03 | Nessun limite singolo default | 6/10 |
| S-04 | API initiator fragile | 6/10 |
| S-05 | IDOR sub_account movimenti | 5/10 |
| S-06 | Health token non obbligatorio in prod | 4/10 |
| S-07 | CSP unsafe-inline | 5/10 |
| S-08 | Cashback loop risk | 5/10 |
| S-09 | Session timeout non configurato | 4/10 |
| S-10 | NFC merchant check assente | 5/10 |
| S-11 | APP_DEBUG non bloccato in prod | 3/10 |

---

## 15. MIGLIORAMENTI UX/UI

| # | Miglioramento | File/Area | Impatto | Difficoltà |
|---|---|---|---|---|
| UX-01 | **QR personale statico sempre visibile in dashboard** | `portal/dashboard.blade.php` | ALTO | BASSA — pulsante "Il mio QR" sotto saldo |
| UX-02 | **Pulsanti INVIA / RICEVI più prominenti in dashboard** | `portal/dashboard.blade.php` | ALTO | BASSA — hero CTA section |
| UX-03 | **Condivisione WhatsApp dalla ricevuta** | `portal/transfer-receipt.blade.php` | ALTO | BASSISSIMA |
| UX-04 | **Tabella movimenti → card list su mobile** | `portal/movements.blade.php` | ALTO | MEDIA |
| UX-05 | **Vibrazione haptic dopo pagamento** | `portal/invia.blade.php` (JS) | MEDIO | BASSISSIMA — `navigator.vibrate()` |
| UX-06 | **Install PWA banner in dashboard** | `layouts/portal.blade.php` | ALTO | BASSA |
| UX-07 | **Pagina ricevuta: opzione "Invia ancora"** | `portal/transfer-receipt.blade.php` | MEDIO | BASSA |
| UX-08 | **Onboarding step KYC: supporto fotocamera mobile** | `portal/onboarding/*` | ALTO | MEDIA — `capture="environment"` su input file |
| UX-09 | **Dark mode: toggle visibile in bottom nav** | `layouts/portal.blade.php` | MEDIO | BASSA |
| UX-10 | **Feedback toast dopo azione** (già presente in parte) | Global | BASSA | BASSA — verificare consistenza |
| UX-11 | **Mappa attività "dove spendo i KY"** | Nuova pagina | ALTO | MEDIA |
| UX-12 | **Numero KY formattato come IBAN** | Tutto il portale | MEDIO | BASSA — `KYB1234-ABCD-5678` |

---

## 16. ROADMAP 0-60 GIORNI

### Settimana 1-2 (Fix critici sicurezza)
- [ ] S-02: Wizard setup PIN obbligatorio al primo pagamento
- [ ] S-03: Default `single_transfer_limit` in SystemSetting
- [ ] S-04: API initiator → usare `$token->creator`
- [ ] S-05: IDOR movimenti sub_account
- [ ] S-10: NFC merchant check
- [ ] B-04: Filtrare fee/cashback da recentRecipients
- [ ] B-05: Fix regex `hasKyAccountNumber`

### Settimana 3-4 (Bug finanziari + UX quick wins)
- [ ] B-01 + B-02: Fee e cashback su `confirmRequest()`
- [ ] UX-03: Condivisione WhatsApp ricevuta
- [ ] UX-01: QR personale in dashboard
- [ ] UX-02: CTA INVIA/RICEVI prominenti
- [ ] UX-08: `capture="environment"` su upload KYC
- [ ] F-01: Bonus benvenuto dopo KYC approvato

### Settimana 5-6 (PWA + Referral + Performance)
- [ ] F-03: Install prompt PWA + banner
- [ ] F-02: Completare referral program con bonus
- [ ] UX-04: Movimenti → card list su mobile
- [ ] Performance: Cache `spentToday/spentThisMonth`
- [ ] Performance: Limit `pendingIncomingRequests` a 20
- [ ] B-06: Rivedere scheduler queue:work per prod
- [ ] S-09: SESSION_LIFETIME=30 in prod .env

---

## 17. ROADMAP 2-4 MESI

- Mappa attività (leaflet.js + lat/lng su Company)
- Pagina pubblica merchant `/m/{slug}` con "Paga in KY"
- Missioni settimanali (gamification base)
- Promozioni merchant (sconti % su listing)
- CSP nonce-based (rimuovere `unsafe-inline`)
- GDPR: route eliminazione account + data retention automatica
- Statistiche velocity moneta in admin
- Test di concorrenza (double-spend)
- Lighthouse mobile ≥90
- NFC HMAC legacy deprecation definitiva

---

## 18. ROADMAP 4-8 MESI

- Badge e livelli utente
- Plugin WooCommerce
- API POS (endpoint dedicati per terminali fisici)
- Notifiche push intelligenti geo-contestuali
- Escrow / pagamento condizionato
- Wallet condivisi (famiglia/gruppo)
- Report circolazione e analisi KPI avanzati per admin
- Two-factor WebAuthn sul pagamento (invece di PIN)
- Internazionalizzazione (multi-lingua)

---

## 19. BACKLOG TECNICO ORDINATO PER PRIORITÀ

| # | Task | Priorità | Difficoltà | Impatto | Tempo stimato |
|---|---|---|---|---|---|
| T-01 | Setup PIN obbligatorio primo pagamento | CRITICA | BASSA | Sicurezza | 4h |
| T-02 | Default single_transfer_limit in SystemSetting | CRITICA | BASSA | Sicurezza | 2h |
| T-03 | Fix API initiator fragile | CRITICA | BASSA | Sicurezza/Audit | 2h |
| T-04 | Fix IDOR sub_account movimenti | CRITICA | BASSA | Sicurezza | 1h |
| T-05 | Fix NFC merchant authorize check | CRITICA | BASSA | Sicurezza | 2h |
| T-06 | Fix recentRecipients (filtra fee/cashback) | ALTA | BASSA | UX | 30min |
| T-07 | Fix regex hasKyAccountNumber | ALTA | BASSA | Bug | 30min |
| T-08 | Fee + cashback su confirmRequest() | ALTA | MEDIA | Finanziario | 3h |
| T-09 | WhatsApp share ricevuta | ALTA | BASSISSIMA | Crescita | 1h |
| T-10 | QR personale in dashboard | ALTA | BASSA | UX | 2h |
| T-11 | CTA INVIA/RICEVI hero in dashboard | ALTA | BASSA | UX | 2h |
| T-12 | Movimenti → card list mobile | ALTA | MEDIA | Mobile UX | 4h |
| T-13 | Install prompt PWA | ALTA | BASSA | Retention | 2h |
| T-14 | Bonus benvenuto post-KYC | ALTA | BASSA | Acquisizione | 3h |
| T-15 | Referral program bonus | ALTA | MEDIA | Crescita | 6h |
| T-16 | Cache spentToday/spentThisMonth | ALTA | BASSA | Performance | 2h |
| T-17 | KYC upload con capture=environment | ALTA | BASSA | Mobile UX | 1h |
| T-18 | Session lifetime 30min prod | MEDIA | BASSA | Sicurezza | 30min |
| T-19 | CSP nonce-based | MEDIA | MEDIA | Sicurezza | 8h |
| T-20 | GDPR: eliminazione account | MEDIA | MEDIA | Legal | 8h |
| T-21 | Mappa attività | MEDIA | MEDIA | Crescita | 12h |
| T-22 | Pagina pubblica merchant | MEDIA | BASSA | Crescita | 4h |
| T-23 | Test concorrenza double-spend | MEDIA | ALTA | Affidabilità | 8h |
| T-24 | Promozioni merchant | MEDIA | MEDIA | Crescita | 8h |
| T-25 | Missioni settimanali | BASSA | ALTA | Gamification | 16h |

---

## 20. TASK PRONTI PER SVILUPPATORE

### T-01 — Setup PIN obbligatorio

**File:** `resources/views/portal/invia.blade.php` + `SendPaymentController.php`

In `SendPaymentController::execute()`, se `$hasPin === false` e `$pinThreshold !== null` e `$amountCents >= $pinThreshold`:
```php
// Reindirizza al wizard di setup PIN prima di procedere
return redirect()->route('portal.invia')->with('portal_warning', 
    'Imposta un PIN di pagamento per poter inviare importi superiori a ' . ky_format($pinThreshold) . ' KY.');
```
Aggiungere modale di setup PIN inline nella pagina `/invia` che si apre automaticamente in questo caso.

### T-02 — Default single_transfer_limit

**File:** `app/Models/SystemSetting.php` (o `AppServiceProvider`)

Aggiungere al metodo `userLimitDefaults()` un campo `default_single_transfer_limit` con valore di fallback (es. 200.000 = 2.000 KY).

In `TransferBookingService::assertTransferWithinLimits()`:
```php
$singleTransferLimit = $limits['per_movement_limit'] 
    ?? $account->spending_limit 
    ?? $creditLimit?->single_transfer_limit
    ?? SystemSetting::userLimitDefaults()->default_single_transfer_limit  // nuovo fallback
    ?? 200000; // hard fallback assoluto
```

### T-03 — Fix API initiator

**File:** `app/Http/Controllers/Api/V1/TransferController.php`, metodo `store()`

```php
// PRIMA (fragile):
$initiator = $fromAccount->ownerUser ?? $fromAccount->company?->users()->first();

// DOPO (corretto):
$token = $request->attributes->get('api_token');
$initiator = $token->creator 
    ?? $fromAccount->ownerUser 
    ?? $fromAccount->company?->users()->orderBy('id')->first();
if (! $initiator) {
    return response()->json(['error' => 'No user associated with this account'], 422);
}
```

### T-06 — Fix recentRecipients

**File:** `app/Http/Controllers/SendPaymentController.php`, metodo `show()`

```php
$recentRecipients = Transfer::where('from_account_id', $currentAccount->id)
    ->where('status', 'booked')
    ->whereNotIn('kind', ['portal_fee', 'portal_cashback', 'portal_credit_note', 'portal_refund'])
    ->with('toAccount')
    ...
```

### T-09 — WhatsApp share

**File:** `resources/views/portal/transfer-receipt.blade.php`

```blade
@php
$waText = urlencode('Ho inviato ' . ky_format($transfer->amount) . ' KY a ' . $counterparty->display_name . ' tramite KosmoPay!');
@endphp
<a href="https://wa.me/?text={{ $waText }}" target="_blank" class="btn-secondary">
    📤 Condividi su WhatsApp
</a>
```

### T-10 — QR personale in dashboard

**File:** `resources/views/portal/dashboard.blade.php`

Aggiungere sotto l'hero saldo:
```blade
<a href="{{ route('portal.card') }}" class="btn-outline-sm">
    📷 Il mio QR per ricevere KY
</a>
```
oppure inline con `{!! QrCode::size(120)->generate(route('nfc.static.pay', $currentAccount->uuid)) !!}`.

---

## 21. FILE DA MODIFICARE (priorità)

| File | Motivo | Urgenza |
|---|---|---|
| `app/Http/Controllers/SendPaymentController.php` | T-01 PIN obbligatorio, T-06 recentRecipients | CRITICA |
| `app/Models/SystemSetting.php` | T-02 default_single_transfer_limit | CRITICA |
| `app/Services/TransferBookingService.php` | T-02 fallback limit, T-08 fee+cashback su confirmRequest | CRITICA |
| `app/Http/Controllers/Api/V1/TransferController.php` | T-03 initiator | CRITICA |
| `app/Http/Controllers/PortalController.php` | T-04 IDOR sub_account | CRITICA |
| `app/Http/Controllers/NfcCardPaymentController.php` | T-05 merchant check | CRITICA |
| `app/Models/Account.php` | T-07 regex hasKyAccountNumber | ALTA |
| `resources/views/portal/transfer-receipt.blade.php` | T-09 WhatsApp share | ALTA |
| `resources/views/portal/dashboard.blade.php` | T-10 QR, T-11 CTA hero | ALTA |
| `resources/views/portal/movements.blade.php` | T-12 card list mobile | ALTA |
| `resources/views/layouts/portal.blade.php` | T-13 install prompt PWA | ALTA |
| `app/Http/Controllers/KycController.php` | T-14 bonus benvenuto post-KYC | ALTA |
| `resources/views/portal/onboarding/step2.blade.php` | T-17 capture=environment | ALTA |
| `app/Models/Account.php` | T-16 cache spentToday | MEDIA |
| `app/Http/Middleware/ContentSecurityPolicy.php` | T-19 nonce-based CSP | MEDIA |

---

## 22. TEST DA AGGIUNGERE

| Test | Tipo | File suggerito | Cosa verifica | Priorità |
|---|---|---|---|---|
| `test_confirmRequest_applies_fee_and_cashback` | Feature | `TransferBookingTest.php` | B-01, B-02 | CRITICA |
| `test_concurrent_payments_no_double_spend` | Feature | `TransferConcurrencyTest.php` (nuovo) | lockForUpdate efficace | CRITICA |
| `test_send_payment_requires_pin_above_threshold` | Feature | `SendPaymentControllerTest.php` (nuovo) | T-01 | CRITICA |
| `test_movements_cannot_access_other_user_subaccount` | Feature | `MovementsSecurityTest.php` (nuovo) | S-05 IDOR | ALTA |
| `test_api_initiator_uses_token_creator` | Feature | `ApiV1Test.php` | T-03 | ALTA |
| `test_nfc_authorize_rejects_wrong_merchant` | Feature | `NfcCardPaymentControllerTest.php` | S-10 | ALTA |
| `test_cashback_does_not_loop_on_cashback_transfer` | Unit | `CashbackServiceTest.php` (nuovo) | S-08 | ALTA |
| `test_has_ky_account_number_validates_all_formats` | Unit | `AccountTest.php` (nuovo) | B-05 | ALTA |
| `test_single_transfer_limit_default_applied` | Feature | `TransferBookingTest.php` | T-02 | ALTA |
| `test_fee_idempotency_no_double_fee` | Feature | `TransferBookingTest.php` | fee non duplicata | ALTA |
| `test_payment_pin_rate_limit` | Feature | `PaymentPinTest.php` | max 5 tentativi | MEDIA |
| `test_webauthn_login_flow` | Feature | `AuthFlowTest.php` | WebAuthn login ok | MEDIA |
| `test_health_endpoint_without_token_hides_details` | Feature | `HealthCheckTest.php` (nuovo) | S-06 | MEDIA |
| `test_recent_recipients_excludes_system_accounts` | Feature | `SendPaymentControllerTest.php` | B-04 | MEDIA |
| `test_pwa_manifest_accessible` | Feature | `PwaTest.php` (nuovo) | manifest 200 | BASSA |
| `test_sw_js_accessible` | Feature | `PwaTest.php` | sw.js 200 | BASSA |

---

## 23. CONCLUSIONE OPERATIVA

KosmoPay è un progetto tecnicamente maturo per una piattaforma di circuito locale: il motore finanziario è corretto e sicuro, la struttura Laravel è pulita, la PWA è parzialmente operativa e il set di funzionalità è ampio. **Non è pronto per il lancio pubblico senza i fix dei punti T-01/T-05** (PIN obbligatorio, limite default, IDOR, NFC merchant check, API initiator).

### Priorità assolute prima del lancio

1. **T-01** — PIN obbligatorio sopra soglia: senza di esso una sessione compromessa = pagamenti liberi
2. **T-02** — Limite singolo default: senza di esso utenti senza configurazione admin non hanno tetto
3. **T-03** — API initiator: audit trail inconsistente in prod
4. **T-04** — IDOR movimenti: privacy utenti
5. **T-05** — NFC merchant: potenziale furto pagamento tramite nonce

### Per diventare facile come Satispay/Revolut

Il flusso `/invia` è già ben strutturato (wizard 3-step, beneficiari, PIN). Mancano:
- Condivisione WhatsApp dalla ricevuta (30 minuti di lavoro)
- QR personale sempre visibile in dashboard (2 ore)
- CTA INVIA/RICEVI più grande e prominente (2 ore)
- Install prompt PWA per far installare l'app (2 ore)

Questi 4 interventi trasformano l'UX mobile da "buona" a "eccellente" con meno di una giornata di sviluppo.

### Per far crescere il circuito

- Bonus benvenuto post-KYC (3 ore) → acquisizione immediata
- Referral program completo (6 ore) → crescita virale
- Pagina pubblica merchant (4 ore) → link condivisibile su Instagram/WhatsApp
- Mappa attività (12 ore) → risponde alla domanda "dove spendo i KY?"

---

*Report generato analizzando il codice sorgente completo: routes, controllers, services, models, middleware, migrations, views, jobs, tests, PWA files.*
