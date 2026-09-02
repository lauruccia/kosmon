# Le due quote: come funzionano davvero, e cosa non va

02/09/2026 — analisi sul codice a `220fe68`. Nessun file toccato.

---

## 1. La mappa in una pagina

Ci sono **due quote distinte**, due tabelle distinte, due servizi gemelli.
Non si sommano quasi mai, ma possono essere dovute dalla stessa persona.

| | **Quota iscrizione (privati)** | **Quota codice agente** |
|---|---|---|
| Importo tipico | 30 € | 480 € |
| Colonna | `users.registration_fee_due_cents` | `users.agent_code_fee_due_cents` |
| Quando nasce | alla registrazione | all'approvazione della richiesta agente |
| Tabella tentativi | `registration_fee_payments` | `agent_code_fee_payments` |
| Servizio | `RegistrationFeeService` | `AgentCodeFeeService` |
| **In euro l'utente riceve KY?** | **SÌ**, 30 KY emessi dal conto di sistema | **NO**, KNM incassa e basta |
| In KY | conto a −30 + fido aggiuntivo 30 | conto a −480 + fido aggiuntivo 480 |
| Cosa blocca | il conto (pagare/incassare/comprare) | la **firma** della nomina, e il conto solo a chi non ha mai pagato un ingresso |
| Scadenza tentativi | sì, `quote:scadi-tentativi` 04:30 | **no** |
| Sollecito | sì, `quote:solleciti-iscrizione` 09:15, una volta sola | **no** (scelta) |
| Ricevuta a chi paga | sì | **no** |
| Ripescaggio incasso in backoffice | sì, «Verifica e accredita» | **no** |
| Bonifico ripreso invece di riaperto | sì | **no** |

### Il valore ZERO significa DUE COSE DIVERSE

È la trappola numero uno di tutto il meccanismo:

- `registration_fee_due_cents = 0` → **SOSPESA**. Non la deve *ora*; si
  riaccende se lascia il percorso agente.
- `agent_code_fee_due_cents = 0` → **ESONERATA**. L'admin ha condonato.

In entrambe: `NULL` = non deve niente e non dovrà mai (i ~1300 iscritti da
prima); `> 0` = la deve, di quella cifra esatta (**scatto**: se domani la
quota passa a 50, chi è entrato a 30 deve 30).

---

## 2. Utente normale (privato) — il percorso passo per passo

### 2.1 Nascita del debito

Due porte:

- **`AuthController::register()`** → `markDueOnRegistration()`. Scrive
  l'importo di oggi, solo se l'interruttore è acceso, solo se
  `account_holder_type === 'private'`, solo se la colonna è **mai stata
  scritta**. Non bloccante: un'eccezione qui non fa fallire la registrazione.
- **`MlmPortalController::registraAgenteStore()`** (l'agente registra qualcuno
  sotto di sé) → se la quota agente è dovuta, `suspendForAgentPath()` scrive
  **zero** (SOSPESA); altrimenti `markDueOnRegistration()` normale. È la
  correzione del 01/09: prima da questa porta non si pagava mai niente.

### 2.2 Cosa gli succede finché non paga

`EnsureRegistrationFeePaid`, agganciato a tutto il gruppo `web` autenticato.
Un elenco **unico** di radici di rotta (`BLOCCATE`) — pagare, incassare,
comprare, concedere un mandato OAuth. Vede tutto (saldo, movimenti, negozio,
profilo), non muove niente. Restano aperti apposta: la **ricarica KYCard**
(serve per arrivare a saldare in KY) e le pagine della quota stessa.
L'addebito via mandato passa da `routes/api.php` e viene fermato altrove, in
`Api\V1\MandateController::charge()` con 403 `circuit_fee_due`.

### 2.3 I quattro modi di pagare — `/quota-iscrizione`

**Saldo KY** (`payWithKy`). Tutto dentro una transazione, con `lockForUpdate`
sull'utente: prima si concede il fido aggiuntivo (senza, il motore
rifiuterebbe di portare a −30 un conto con fido zero), poi il movimento
utente → conto di sistema con `idempotency_key = regfee_<uuid>`, poi
`registration_fee_paid_at`. La notifica parte **fuori** dalla transazione.

**Carta (Stripe).** Ogni click apre una **riga nuova** — voluto: riusare la
riga sovrascriverebbe la sessione e un pagamento sulla vecchia non verrebbe
mai accreditato. `success_url` porta a `/quota-iscrizione/esito/{uuid}`. Il
`catch` è su `\Throwable` e non `\Exception` (la libreria Stripe mancante
solleva un `\Error`, ed è il bug visto in produzione l'01/09).

**PayPal.** `paypalCreateOrder` → l'utente paga → ritorno su
`paypal-capture/{uuid}` → capture → se `COMPLETED`, `completeEuroPayment()`.

**Bonifico.** `startOrResumeBankTransfer()`: se ce n'è già uno aperto lo
**riprende**, così la causale (che contiene l'uuid) resta quella scritta sul
bonifico vero. C'è anche «cambia metodo» (`abandonBankTransfer`). L'admin
conferma o rifiuta da `/admin/quote-iscrizione`.

### 2.4 L'incasso in euro — `completeEuroPayment()`

Emette 30 KY **dal conto di sistema all'utente**, iniziati da un super admin
(l'unico che bypassa autorizzazione e fido). Idempotente su due livelli: la
guardia sullo stato sotto lock, e la `idempotency_key` del transfer — che è
l'unica cosa che regge la corsa vera fra webhook e pagina di esito. Se
risulta che la quota era già saldata da un altro pagamento, i KY si
accreditano lo stesso (i soldi sono arrivati) ma finisce
`quota_gia_saldata: true` nell'audit log e un `Log::warning`.

### 2.5 Le tre porte che accreditano

1. **Webhook** `/stripe/webhook` (`KyCardController::stripeWebhook`) —
   condiviso da quattro incassi: KYCard, upgrade piano, quota privati, quota
   agente. Guardia: «non è né saldata né annullata» (non più `isPending()`).
   La prova la dà `sessionMatches()`.
2. **Pagina di esito** `/quota-iscrizione/esito/{uuid}` — stessa tolleranza,
   **solo per Stripe**.
3. **Backoffice**: conferma bonifico, e «Verifica e accredita» sulle righe
   `failed` in euro (prova raccolta dal controller: sessione Stripe verificata,
   ordine PayPal COMPLETED, o conferma esplicita dell'admin per il bonifico).

### 2.6 Le uscite

- **Annullamento** (`cancel`): storno per inversione **solo se il movimento
  esiste ancora ed è `booked`**, quota di nuovo dovuta dell'importo *di quel
  pagamento*, fido aggiuntivo a zero, notifica. I movimenti di quota non sono
  più cancellabili da `/admin/movimenti`.
- **Richiesta da admin** (`requestFrom`): uno alla volta, dalla scheda utente,
  con audit log. Serve anche per i ~1300 vecchi iscritti (`NULL`).
- **Sospensione da admin** (`adminSuspendForAgentPath`): per gli arretrati,
  chi era stato approvato agente prima del 02/09 e si è ritrovato addosso
  tutte e due.

---

## 3. Agente — il percorso passo per passo

Il percorso è: **richiesta → approvazione → [QUOTA] → firma con OTP →
`mlm_role = 'agente'`**. La quota sta fra approvazione e firma, e la firma è
l'atto che fa diventare agente: quindi non esiste un istante in cui qualcuno
opera da agente senza aver pagato. Il blocco è in
`MlmAgentContractController` (tre punti: `show`, e due dentro `sign`).

### 3.1 Nascita del debito — tre porte

Tutte e tre chiamano `markDueOnApproval()` (scatto, non riscrive una colonna
già scritta, salta chi è già agente):

1. `Admin\MlmAgentRequestController::approve()`
2. `Admin\MlmAgentRequestController::promote()` (l'admin promuove d'ufficio)
3. `MlmPortalController::registraAgenteStore()` (l'agente ne registra uno sotto)

Nelle prime due, subito dopo, `quoteAllApprovazione()` chiama
`suspendOnAgentApproval()`: **i 30 dei privati si sospendono** — decisione del
02/09, l'agente paga una quota sola. Ma solo se i 480 sono davvero in carico
(`isOnFeePath`): se l'interruttore agenti è spento, non c'è niente che lo
copra e i 30 se li tiene.

Asimmetria voluta e importante: alla **creazione** dal portale agente `NULL`
diventa SOSPESA; all'**approvazione** `NULL` resta `NULL` (i vecchi iscritti
non devono ritrovarsi un debito mai avuto).

### 3.2 Cosa blocca

Dal 02/09 la quota agente **non ferma il conto a tutti**. In
`EnsureRegistrationFeePaid`: se deve i 480 ma **non** è `isSuspendedFor` —
cioè i 30 li aveva già pagati, o non li ha mai dovuti — il conto continua a
funzionare e gli manca solo la firma. Ferma il conto solo a chi nel circuito
non ha ancora pagato nessun ingresso.

### 3.3 Pagamento

Identico ai privati nei quattro metodi, con **una differenza sostanziale**:
`completeEuroPayment()` non muove **nessun** conto. I 480 in euro non
accreditano KY, non c'è nessun transfer, e quindi non c'è nessuna
`idempotency_key` a fare da seconda difesa: **l'unica difesa è il lock**.

In KY invece sì: −480 sul conto più fido aggiuntivo 480. Da notare che 480 KY
di scoperto sono sedici volte 30 e sono **moneta creata dal circuito**;
l'admin può spegnere il solo metodo KY per gli agenti.

### 3.4 Le quattro uscite (01/09) — «il denaro non si muove per effetto collaterale»

1. **Rinuncia** (`giveUp`): presupposto è il *percorso* (richiesta `approved`,
   firma non fatta), non lo stato della quota. Se non pagata, il debito si
   cancella; se pagata, **resta pagata e nessun KY torna indietro**.
2. **Annullamento** (`cancel`): gemello di quello dei privati. In euro non c'è
   niente da stornare — **il rimborso resta da fare a mano** su Stripe/PayPal.
3. **Rifiuto** (`reject`): `dropUnpaidDebt()` cancella il solo debito non
   pagato; se aveva già saldato, lo dice a schermo a chi rifiuta.
4. **Esonero** (`waive`): quota a zero, motivo obbligatorio, nessun movimento e
   **nessun pagamento finto in tabella**. Revocabile finché non ha firmato,
   e rimette in carico l'importo di prima letto **dall'audit log**.

Rinuncia e rifiuto chiamano poi `resumeAfterAgentPath()`: i 30 sospesi si
riaccendono.

---

## 4. Cosa non va

### 🔴 A1 — Chi ha PAGATO i 480 e poi esce si ritrova a dovere anche i 30

`resumeAfterAgentPath()` guarda **solo** le colonne della quota privati: non
sa niente di `agent_code_fee_paid_at`. Quindi:

> Tizio entra dal portale di un agente (30 SOSPESI), paga 480 €, poi rinuncia
> — oppure l'admin lo rifiuta. La quota agente resta pagata (giusto, i soldi
> sono arrivati), ma i 30 **si riaccendono** e il conto gli si blocca.

Ha pagato 480 € per un codice che non avrà mai, e il circuito gliene chiede
altri 30. Vale identico in `giveUp()` e in `reject()`.

**Fix**: in `resumeAfterAgentPath()` aggiungere alla guardia dentro il lock
«e la quota agente non risulta pagata». Se ha pagato l'ingresso, l'ingresso è
pagato — la colonna può tornare a `NULL`, non all'importo.

*(Se invece la regola voluta di Laura è «i 480 pagano il codice, non
l'ingresso», allora va bene così, ma andrebbe scritto nel messaggio della
notifica: oggi l'utente riceve un «devi 30 €» senza nessuna spiegazione.)*

### 🔴 A2 — Rinuncia o rifiuto mentre un checkout è aperto: 480 € incassati per niente

Né `giveUp()` né `dropUnpaidDebt()` chiudono le righe `pending`. Ma il webhook
(e la pagina di esito) accreditano **qualunque riga non `completed` e non
`cancelled`**. Quindi:

1. clicca «paga con carta» → riga `pending`, sessione Stripe aperta;
2. torna indietro e rinuncia → `agent_code_fee_due_cents = NULL`, richiesta
   `cancelled`, i 30 si riaccendono;
3. paga lo stesso su Stripe (la scheda era ancora aperta) →
4. webhook: riga `pending`, non cancellata → `sessionMatches` OK →
   `completeEuroPayment()` → riga `completed`, `agent_code_fee_paid_at = now()`.

Risultato: 480 € incassati, nessun codice agente, nessuna mail all'utente
(vedi A5), richiesta cancellata, e i 30 da pagare.

**Fix**: in `giveUp()` e `dropUnpaidDebt()` marcare `cancelled` (non `failed`
— `failed` è ancora ripescabile) le righe `pending` / `pending_bank_transfer`
di quell'utente, dentro la stessa transazione. Stessa cosa in `reject()`.

### 🟠 A3 — Il bonifico della quota agente riapre una causale nuova a ogni visita

`AgentCodeFeeController::bankTransfer()` chiama `startPayment()` diretto.
Non esistono `pendingBankTransferFor()`, `startOrResumeBankTransfer()` né
«cambia metodo» — sono la correzione già fatta l'01/09 **solo sui privati**.

L'utente che torna sulla pagina dopo essere andato in banca vede istruzioni
con una causale **diversa** da quella che ha scritto sul bonifico, e in
`/admin/quote-codice-agente` compaiono tre righe `pending_bank_transfer` per
la stessa persona. Su 480 € questo pesa più che su 30.

**Fix**: copiare i tre metodi dal servizio dei privati e la variabile
`bonifico` nella view.

### 🟠 A4 — Nessun ripescaggio per la quota agente

Non esiste `retryEuroCredit` né il bottone «Verifica e accredita». Se
`completeEuroPayment()` fallisce nella scrittura (deadlock, audit log), la
riga va `failed` e da lì **non si recupera più da backoffice**:
`adminConfirmBankTransfer` pretende `isPendingBankTransfer()`, e la riga ora è
`failed`. Per Stripe si salva la pagina di esito (se l'utente ci ritorna);
per PayPal e per il bonifico non si salva niente.

### 🟠 A5 — Chi paga 480 € non riceve nessuna ricevuta

`RegistrationFeePaidNotification` esiste per i 30. Per i 480 non c'è niente:
né in `payWithKy()` né in `completeEuroPayment()`. L'unico segnale è la pagina
di esito, che l'utente vede solo se non chiude la scheda. Manca un
`AgentCodeFeePaidNotification` (le altre due, Cancelled e Waiver, ci sono già).

### 🟠 A6 — `resumeAfterAgentPath()` usa l'importo di OGGI, non lo scatto sospeso

Il commento del metodo dice «la colonna conteneva zero, non c'era nessun
importo da conservare» — **non è più vero dal 02/09**:
`suspendOnAgentApproval()` scrive `context.amount` nell'audit log
`registration_fee.suspended_on_agent_approval`. Quindi chi si era registrato a
30, viene approvato, la quota passa a 50, viene rifiutato → si ritrova a
dovere **50**, non 30.

**Fix**: leggere l'audit log come fa già `revokeWaiver()`, con ripiego sulle
impostazioni. Il modello giusto è già scritto nel progetto.

### 🟠 A7 — Se l'interruttore è spento, la quota sospesa non si riaccende MAI

`suspendOnAgentApproval()` non guarda l'interruttore (voluto: «se l'admin l'ha
spenta dopo aver messo in carico i 30, quei 30 sono comunque dovuti»).
`resumeAfterAgentPath()` invece esce subito se
`! registrationFeeEnabled()` o se l'importo è ≤ 0. Asimmetria: spegnere
l'interruttore un giorno, e riaccenderlo il giorno dopo, condona in silenzio
tutti quelli che nel frattempo sono usciti dal percorso agente. La colonna
resta a zero e nessun sollecito la trova (il comando filtra `> 0`).

**Fix**: in `resumeAfterAgentPath()` togliere la guardia sull'interruttore
quando l'importo da riaccendere viene dall'audit log (vedi A6): quella quota
era già stata messa in carico, riaccenderla non è «attivare la quota».

### 🟡 A8 — `paypalCapture()` guarda ancora `isPending()` — in tutti e due i controller

La tolleranza «non saldata e non annullata» è stata portata su webhook e
pagina di esito, ma non qui. Una riga finita `failed` (accredito andato
storto, o chiusa dal comando delle 04:30) al ritorno da PayPal viene saltata:
`redirect` alla pagina di esito, che per PayPal non verifica niente (vedi A9).
Nei privati resta il bottone di ripescaggio; negli agenti non resta nulla.

### 🟡 A9 — La pagina di esito verifica solo Stripe, mai PayPal

`success()` ha un solo ramo, `METHOD_STRIPE`. Non esiste nessun webhook PayPal
in questo endpoint. Quindi per PayPal l'unica strada che accredita è il
`capture` sincrono: se l'utente chiude la scheda o la rete cade fra il
pagamento e il ritorno, **nessun processo automatico recupera l'incasso**.

**Fix minimo**: nella pagina di esito, per `METHOD_PAYPAL` con
`paypal_order_id` valorizzato, rileggere l'ordine e accreditare se `COMPLETED`
— è esattamente ciò che fa già `adminRetryCredit()`.

### 🟡 A10 — `giveUp()` senza lock né transazione

È l'unico dei metodi di uscita che scrive quattro campi con un `forceFill`
nudo, fuori da `DB::transaction`. Doppio click → due audit log
`mlm.agent_request.given_up`, e una finestra in cui la richiesta è
`cancelled` ma il debito è ancora lì. Tutti i suoi gemelli (`cancel`,
`waive`, `revokeWaiver`, `resumeAfterAgentPath`, `suspendOnAgentApproval`)
girano sotto `lockForUpdate`.

### 🟡 A11 — Il fido aggiuntivo da 480 resta a vita a chi paga in KY e poi rinuncia

`giveUp()` con quota pagata non tocca `agent_code_fee_ky_allowance_cents`.
L'utente resta un privato non agente con 480 KY di capienza in più del suo
fido, per sempre. È coerente («ha pagato»), ma è moneta: solo `cancel()` dal
backoffice la toglie. Almeno andrebbe segnalato nel messaggio all'admin.

### 🟡 A12 — Nessuna scadenza dei tentativi per la quota agente

`quote:scadi-tentativi` legge solo `registration_fee_payments`. In
`/admin/quote-codice-agente` le righe `pending` di chi ha cambiato idea non si
chiudono mai e la colonna «Stato» smette di dire qualcosa — lo stesso
problema che sui privati era stato giudicato degno di un comando notturno.
(Il **sollecito** invece è una scelta consapevole: il caso si gestisce di
persona.)

### ⚪ A13 — Messaggio sbagliato: euro chiamati KY

`RegistrationFeeController::adminRequest()` dice
`'Quota di ' . ky_format($importo) . ' KY richiesta a …'`. La quota di
iscrizione è in **euro** (30 €). Nel messaggio di approvazione, due file più
in là, lo stesso helper è seguito da `' €'`. Riga 433.

---

## 5. Migliorie strutturali

**M1 — I due servizi sono gemelli e continuano a divergere.**
`RegistrationFeeService` (986 righe) e `AgentCodeFeeService` (741) hanno
`startPayment`, `payWithKy`, `completeEuroPayment`, `cancel`, `markFailed`,
`accountFor`, `isDueFor`, `amountDueFor` quasi identici. **Nove correzioni su
dieci degli ultimi tre giorni sono state fatte su uno solo dei due** — A3, A4,
A5, A12 sono tutte questo. Vale la pena estrarre una classe base astratta (o
un trait) con il ciclo di vita del pagamento, lasciando nelle sottoclassi le
sole due differenze vere: *chi emette i KY in euro* e *cosa si sblocca quando
è saldata*. Un test parametrico sui due servizi troverebbe da solo le
divergenze future.

**M2 — Un solo pannello «Quote» con due schede**, invece di due pagine
gemelle. Oggi per capire la posizione di una persona bisogna aprire
`/admin/quote-iscrizione`, `/admin/quote-codice-agente` e la scheda utente.

**M3 — Una vista di riconciliazione**: quote segnate pagate senza pagamento
dietro, fidi aggiuntivi accesi senza quota pagata, agenti firmati con quota
da pagare. I tre controlli esistono già come SQL in
`database/sql/2026_09_01_verifica_quote.sql`; portarli in una pagina di
backoffice li rende una cosa che qualcuno guarda davvero.

**M4 — Un solo posto dove leggere «quanto ha pagato d'ingresso questa
persona»**. Oggi la risposta si compone da quattro colonne e un audit log.

---

## 6. Priorità suggerita

| | Cosa | Perché prima |
|---|---|---|
| 1 | **A2** righe `pending` non chiuse su rinuncia/rifiuto | incassa 480 € senza dare niente |
| 2 | **A1** i 30 dopo aver pagato i 480 | chiede soldi a chi ha già pagato |
| 3 | **A5** ricevuta della quota agente | 480 € senza nessuna conferma scritta |
| 4 | **A3** bonifico agente riaperto ogni volta | causali non riconciliabili |
| 5 | **A6 + A7** importo e interruttore in `resumeAfterAgentPath` | importi sbagliati, condoni silenziosi |
| 6 | **A9 + A8** PayPal senza rete di sicurezza | incassi persi, rari ma irrecuperabili |
| 7 | **A4, A12, A10, A11, A13** | robustezza e pulizia |
| 8 | **M1** base comune ai due servizi | evita la prossima divergenza |
