# Proposte di miglioramento — KMoney
**Data:** 2026-06-12 · **Analisi:** funzionalità, sicurezza, usabilità, mobile, performance
**Nessuna modifica applicata — solo proposte.** Complementare ad ANALISI_QUALITA.md (2026-06-04): i punti già segnalati lì non vengono ripetuti, salvo quelli ancora aperti.

---

## Riepilogo priorità

| Priorità | Tema | N° proposte |
|---|---|---|
| 🔴 P0 | Bug finanziari da verificare subito | 4 |
| 🟠 P1 | Sicurezza e integrità contabile | 7 |
| 🟡 P2 | Usabilità e facilitazione scambio KY | 8 |
| 🟢 P3 | Mobile, performance, processi | 8 |

---

## 🔴 P0 — Bug finanziari (verificare prima di tutto)

### 1. `confirmRequest()` addebita come commissione **l'intero importo** del trasferimento
`TransferBookingService.php` riga ~223:
```php
$this->bookFee($fromAccount, $systemAccount, (int) $pendingTransfer->amount, $kind, $pendingTransfer);
```
Negli altri due punti il codice calcola prima `$fee = TransactionFee::calculate($kind, $amount)` e chiama `bookFee(..., $fee, ...)`. Qui invece viene passato **l'importo pieno** come fee: chi conferma una richiesta d'incasso viene addebitato **due volte** (importo + "commissione" pari all'importo). Possibile causa degli squilibri indagati in `diagnostica_squilibrio_circuito.sql`.
**Proposta:** calcolare la fee con `TransactionFee::calculate()` e aggiungere `if ($fee > 0)`. Aggiungere un test che confermi una richiesta con fee configurata e verifichi i saldi finali.

### 2. Rimborsi e note di credito senza idempotency key dal chiamante
`refundMerchant()` e `issueCreditNote()` generano internamente `Str::uuid()` come chiave: un doppio click / doppio submit crea **due rimborsi reali**. (Il limite `maxRefundable` mitiga solo in parte; le note di credito non hanno alcun tetto.)
**Proposta:** accettare `idempotency_key` dal controller (generata nel form come hidden field) come già avviene per `book()`.

### 3. Rimborso di un rimborso
`$refundableKinds` include `portal_refund`: A rimborsa B, B può "rimborsare il rimborso" creando catene di movimenti senza senso commerciale.
**Proposta:** escludere `portal_refund` dai kind rimborsabili (per correzioni esiste la nota di credito).

### 4. Controlli anti-frode applicati solo a `book()`
`assertNotAnomalousActivity()` (blocco temporaneo conto + 3 tentativi falliti) non viene eseguito in `confirmRequest()`, `refundMerchant()` e `issueCreditNote()`: un conto bloccato può ancora muovere fondi confermando richieste o emettendo note di credito.
**Proposta:** estrarre il controllo e applicarlo a tutti i metodi che spostano denaro.

---

## 🟠 P1 — Sicurezza e integrità

### 5. Ricerca destinatari `/invia/cerca` troppo permissiva
Nessuna lunghezza minima della query (stringa vuota → `LIKE '%%'` restituisce i primi 10 conti) e ricerca per **substring su email e telefono**: un membro qualsiasi può enumerare contatti degli altri iscritti.
**Proposta:** minimo 3 caratteri, throttle dedicato, match **esatto** (non LIKE) su email/telefono, e non restituire mai email/telefono nella risposta.

### 6. Limite giornaliero/mensile conteggiato per *initiator*, non per conto
In `assertTransferWithinLimits()` la somma "spesa oggi" filtra `where('initiated_by', $initiator->id)`: un'azienda con 3 utenti può spendere 3× il limite giornaliero del conto. Inoltre il pattern `Cache::remember(60s)` seguito subito da `Cache::forget()` annulla il beneficio della cache e in burst concorrenti può far sforare il limite.
**Proposta:** conteggiare per `from_account_id` (il limite utente-delegato resta come vincolo aggiuntivo) e rimuovere la cache o sostituirla con lock/contatore atomico.

### 7. Job notturno di quadratura contabile
Esistono script SQL diagnostici manuali, segno che gli squilibri sono già accaduti.
**Proposta:** comando Artisan schedulato ogni notte che verifichi gli invarianti: somma debiti = somma crediti per ogni transfer; `available_balance` = somma ledger per ogni conto; somma di tutti i saldi = 0 (al netto del conto sistema). In caso di scostamento → notifica admin + Sentry. Costo basso, valore altissimo per una piattaforma finanziaria.

### 8. Fee e cashback dentro la transazione principale
I commenti dicono "fuori dalla transazione per evitare deadlock", ma `bookFee()` e `CashbackService::applyIfEligible()` vengono chiamati **dentro** `DB::transaction()`: allunga la finestra di lock sui conti (incluso il conto sistema, lockato a ogni pagamento → collo di bottiglia globale) e in caso di errore silenziato (`catch (\Throwable) {}`) non lascia traccia.
**Proposta:** spostarli in `DB::afterCommit()` o in un job in coda; loggare sempre le eccezioni delle fee (oggi sparirebbero senza traccia).

### 9. Step-up authentication su più azioni sensibili
Oggi `step.up` copre solo disattivazione 2FA e API token. **Proposta:** estenderlo a: modifica beneficiari, creazione webhook, pagamenti sopra soglia configurabile, modifica limiti sottoconti. Le passkey già implementate rendono lo step-up rapido (un tocco con biometria).

### 10. Hardening header e API
CSP è già ottima; mancano `Strict-Transport-Security`, `Referrer-Policy`, `Permissions-Policy`. Le API v1 in lettura non hanno throttle (solo `POST /transfers` ha `throttle:10,1`). `script-src 'unsafe-inline'` potrebbe evolvere verso nonce.
**Proposta:** aggiungere i 3 header, `throttle:60,1` sulle GET API, e (opzionale) IP allowlist per token API.

### 11. 2FA / PIN pagamento non obbligatori
`TwoFactorChallenge` salta il controllo se l'utente non ha mai configurato il 2FA.
**Proposta:** per un circuito monetario, rendere obbligatorio almeno uno tra: 2FA TOTP, passkey, PIN pagamento — con wizard al primo login. In alternativa: obbligatorio solo per chi supera X KY/mese.

---

## 🟡 P2 — Usabilità: agevolare lo scambio di KY

### 12. Messaggi d'errore finanziari in inglese e poco actionable
Molte `RuntimeException` arrivano all'utente in inglese ("Transfer exceeds the daily outgoing limit") in un'interfaccia interamente italiana, senza dire **quanto** è il limite né **cosa fare**.
**Proposta:** catalogo di eccezioni tipizzate con messaggi italiani che includano i numeri ("Hai raggiunto il limite giornaliero di 500 KY. Residuo oggi: 120 KY.") e una CTA ("Richiedi aumento limite"). È probabilmente l'intervento col miglior rapporto costo/beneficio sull'esperienza utente.

### 13. Limiti di default troppo bassi e invisibili
Default: 500 KY/giorno per conto, 2.000 KY per movimento. Gli utenti li scoprono solo sbattendoci contro.
**Proposta:** mostrare nella dashboard e nella pagina di pagamento i limiti attivi e il residuo giornaliero (già fatto per i delegati — estenderlo a tutti); flusso self-service "richiedi aumento limite" riusando il pattern `CreditLimitRequest`.

### 14. Hub pagamenti: troppe opzioni allo stesso livello
QR, NFC, Sonic, codice, link, richiesta testuale, W3C Payment Request: potente ma dispersivo per un commerciante non tecnico.
**Proposta:** due sole azioni primarie ("Incassa" / "Paga") con scelta del metodo come secondo passo, ricordando l'ultimo metodo usato per utente (1 tap per il caso abituale). Le opzioni avanzate sotto "Altri metodi".

### 15. Pagamento ripetuto in 1 tap
La rubrica beneficiari c'è e il salvataggio è automatico (ottimo).
**Proposta:** sul dettaglio movimento e in dashboard, pulsante "Ripeti pagamento" precompilato; beneficiari "preferiti" in cima al form Invia; importi rapidi (ultimi 3 importi usati verso quel beneficiario).

### 16. Directory del circuito più "commerciale"
Lo scambio KY cresce se i membri si trovano a vicenda. Esistono shop/annunci e settori.
**Proposta:** directory pubblica interna con ricerca per settore/zona, badge "accetta KY %", pulsante "Paga questa azienda" che apre il form precompilato, e segnalazione delle aziende in stato "solo 100% KY" (che hanno bisogno di vendere) per stimolare il matching domanda/offerta — è il cuore del modello Sardex.

### 17. Promemoria automatici sulle richieste di pagamento
Le richieste pending scadono via `ExpirePaymentRequests` ma non risulta un sollecito.
**Proposta:** notifica push/email al debitore 24h e 1h prima della scadenza; al creditore quando la richiesta scade non pagata, con CTA "Reinvia".

### 18. Ricevute condivisibili
**Proposta:** sul transfer-receipt, pulsanti "Condividi" (Web Share API, già perfetta nel contesto PWA) e download PDF — la ricevuta che gira su WhatsApp è anche marketing del circuito.

### 19. Onboarding: visibilità dello stato pratica
Il flusso (registrazione → KYC → approvazione → contratto) è solido ma l'attesa approvazione è una scatola nera.
**Proposta:** pagina stato con timeline ("Documenti ricevuti ✓ → In revisione → Approvato"), tempi medi indicati, e notifica push/email a ogni cambio stato. Ridurre il drop-off in onboarding = più membri attivi che scambiano KY.

---

## 🟢 P3 — Mobile, performance, processi

### 20. Conferma pagamento con passkey/biometria
WebAuthn è già integrato per il login. **Proposta:** usarlo anche come conferma di pagamento su mobile (Face ID/impronta al posto del PIN) — percezione di sicurezza da app bancaria, frizione quasi nulla.

### 21. PWA: spinta all'installazione
Manifest e service worker sono ben fatti. **Proposta:** banner "Aggiungi a schermata Home" custom (intercettare `beforeinstallprompt`, istruzioni dedicate per iOS), `shortcuts` nel manifest ("Incassa QR", "Paga", "Movimenti") e `screenshots` per la finestra di install più ricca su Android.

### 22. QA mobile sistematico
Gli ultimi commit sono fix di scroll/sidebar mobile trovati a mano.
**Proposta:** checklist di smoke-test mobile (o test Playwright con viewport 390px) sui 5 flussi chiave: login, paga, incassa QR, movimenti, richieste. Eviterebbe regressioni ricorrenti.

### 23. Indici DB per le query sui limiti
`spentToday()`/limiti giornalieri sommano su `(from_account_id, status, booked_at)` ma l'indice esistente è solo `(from_account_id, status)`; con la crescita dei movimenti la scansione peggiora.
**Proposta:** indice composito `(from_account_id, status, booked_at)`; indici su `reversed_transfer_id` e `related_transfer_id` (usati nei sum dei rimborsi e nelle fee).

### 24. KPI dashboard consolidati
Già segnalato in ANALISI_QUALITA (punto 10), ancora attuale: la dashboard esegue molte aggregazioni separate.
**Proposta:** una query unica con `CASE WHEN` o cache 5-10 min per i KPI non realtime (il trend mensile è già cacheato — estendere il pattern).

### 25. Lock sul conto sistema = collo di bottiglia
Ogni fee/cashback locka il conto sistema: con volumi alti i pagamenti si serializzano tutti su quella riga.
**Proposta (quando i volumi crescono):** accodare fee e cashback in un job batch (es. ogni 10s) invece di scriverli in linea nel pagamento.

### 26. Pulizia repository
Nella root del progetto (e quindi potenzialmente in produzione via Git deploy): `vendor.zip`, `vendor (2).zip`, dump SQL (`allinea_db_mysql.sql.gz`), script diagnostici (`check_laura.php`, `allinea_db.php`), `index.html`. Gli script PHP raggiungibili via web sono un rischio reale.
**Proposta:** spostarli fuori dal repo o in una cartella esclusa, aggiornare `.gitignore`, verificare che non siano serviti dal webroot in produzione.

### 27. CI e backup
Si lavora con push GitHub → cPanel. **Proposta:** GitHub Action che esegue `php artisan test` a ogni push (51 file di test già pronti — usarli come rete di sicurezza) e backup automatico giornaliero del DB MySQL in produzione con retention 30 giorni.

---

## Ordine d'intervento suggerito

1. **Subito (1-2 giorni):** punti 1–4 (bug finanziari) + 5 (privacy ricerca) + 26 (file nel repo).
2. **Settimana 1-2:** 6, 7, 8 (integrità), 12, 13 (errori e limiti visibili).
3. **Mese 1:** 14–19 (usabilità scambio KY), 10, 11 (hardening).
4. **Continuo:** 20–25, 27 (mobile, performance, CI).

I punti 12–16 sono quelli con l'impatto più diretto sull'obiettivo dichiarato: **rendere più facile e frequente lo scambio di KMoney tra i clienti.**
