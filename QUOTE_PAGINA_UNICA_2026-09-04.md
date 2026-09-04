# Le tre quote in una pagina sola — 04/09/2026

> **Richiesta di Laura:** «Per quanto riguarda le quote di iscrizione (privati,
> aziende, agenti) vorrei un'unica pagina dove attivare o disattivare le 3
> quote, impostare l'importo, metodi di pagamento, fido ed eventuale
> restituzione in ky per chi paga in € con importo deciso da me.
> Semplifichiamo.»

**Stato: scritto e verde.** 18 test nuovi, le tre suite delle quote passano
(120 + 74 + 34), backoffice e MLM pure. **Nessun commit** — la regola resta.

**Prima del codice va eseguito** `database/sql/2026_09_04_quote_leve_comuni.sql`
su entrambi i server.

---

## 1. Cosa vede Laura

Una sola voce nel menu di amministrazione, **Quote del circuito**, che apre
`/admin/quote`:

- **in cima, le tre quote una sotto l'altra**, con gli stessi cinque comandi
  ciascuna: interruttore, importo, **KY restituiti a chi paga in euro**, **fido
  aggiuntivo a chi paga in KY**, metodi di pagamento;
- **sotto, i pagamenti in tre schede** (privati / agenti / aziende), con lo
  stesso filtro per stato e gli stessi bottoni di prima: conferma bonifico,
  rifiuta, annulla quota, verifica e salda.

I tre indirizzi vecchi (`/admin/quote-iscrizione`, `/admin/quote-codice-agente`,
`/admin/quote-apertura-conto`) **portano alla pagina unica, sulla scheda
giusta**: non sono stati cancellati perche' ci puntano i link della scheda
utente, i rimandi dei controller dopo ogni azione e i segnalibri.

**Tre bottoni «Salva», uno per quota, e non uno solo.** Salvare una quota non
deve poter toccare le altre due, e un errore su una lascia le altre come
stavano. C'e' un test che lo inchioda.

---

## 2. Le due leve, adesso uguali per tutte e tre

Erano nate ieri per la sola quota di apertura conto. Da oggi sono le stesse per
tutte, con gli stessi nomi, e **il motore comune le legge da un posto solo**.

| | **Restituzione in KY** (chi paga in euro) | **Fido aggiuntivo** (chi paga in KY) |
|---|---|---|
| **cosa fa** | il conto di sistema conia N KY verso chi ha pagato | il massimale sale dell'importo della quota, cosi' il fido che aveva resta intero |
| **chi decide N** | Laura, quota per quota | e' sempre pari all'importo: si decide solo se darlo |
| **da spento / a zero** | la quota e' solo un incasso | la quota si mangia il fido che l'utente ha gia'; senza fido proprio non riesce a pagare in KY |
| **valore di partenza** | privati **= l'importo della quota**, agenti **0**, aziende **0** | **acceso** su tutte e tre |

**Le due leve non si incrociano**: chi paga in KY non riceve nessuna
restituzione, chi paga in euro non riceve nessun fido.

### La riga che conta: perche' i privati partono dall'importo

Fino a ieri il privato che pagava in euro riceveva **sempre** tanti KY quanti ne
aveva pagati — in euro la quota di iscrizione non e' un costo, e' un acquisto di
KY — e il numero stava **cablato nel codice**. Diventando un'impostazione, se
partisse da zero il primo privato che paga dopo il rilascio verserebbe 30 euro
senza ricevere niente.

L'SQL di produzione la mette **pari all'importo che la quota ha davvero su quel
server**, non ai 30,00 del default.

> **Da qui in poi la restituzione non segue piu' l'importo da sola.** Alzando la
> quota dei privati a 50,00 la restituzione resta a 30,00 finche' non la si
> cambia anche li'. E' il prezzo di poterla decidere, e la pagina lo dice a
> schermo.

### Il trattamento della singola persona

Le due leve hanno un **ripiego per singolo utente**, sulla sua scheda, adesso su
tutte e tre le quote (prima solo sulle aziende).

**NULL non e' zero.** Campo vuoto e «come da impostazioni» vogliono dire «segui
il pannello»; `0` e «no» sono decisioni prese per quella persona e **restano
ferme anche se domani il default cambia**. E' l'unico motivo per cui sono
colonne separate invece di scrivere direttamente il numero — e c'e' un test che
lo sorveglia.

La quota delle aziende e' l'unica che **restringe**: il trattamento si da' solo a
un'azienda vera, cioe' `account_holder_type = 'company'` **e** `company_id`
valorizzato.

---

## 3. Le trappole da ricordare

- **Una casella non spuntata e una casella assente arrivano identiche**, e
  `boolean()` risponde `false` a tutte e due. Senza una guardia, una richiesta
  che non porta i due campi delle leve — un vecchio segnalibro, uno script, un
  test scritto prima — **spegnerebbe il fido**, e il prossimo che paga in KY si
  vedrebbe rifiutare l'addebito. Da qui il marcatore `<prefisso>_form` che la
  pagina manda: le leve si scrivono solo se il form le porta. Test dedicato.
- **Ogni conto nasce con un limite giornaliero di uscita di 500,00 KY**
  (`Account::booted`). Una quota in KY che lo supera non passa, e l'utente legge
  «hai raggiunto il limite giornaliero» senza capire perche'. Laura ha deciso di
  **non** esentare le quote dai limiti di spesa: l'unica difesa e' l'avviso in
  pagina, che compare solo sulle quote sopra i 500,00.
- **Blade non compila una direttiva attaccata a una parola** (`quell'importo@if(...)`):
  la lascia letterale, sbilancia il blocco, e la pagina esplode solo quando
  qualcuno la apre. Nella tabella dei pagamenti **i testi delle conferme si
  calcolano in PHP sopra la riga**, non dentro l'attributo `onsubmit`, dove per
  giunta gli apostrofi vanno scritti `\'` o chiudono la stringa JavaScript.
- **Quanti KY siano tornati indietro lo dice il MOVIMENTO, non l'importo della
  quota.** La ricevuta e la pagina di esito leggono `payment->transfer->amount`:
  l'impostazione di oggi direbbe il falso a chi ha pagato ieri, e `ky_amount`
  direbbe il falso a chiunque abbia una restituzione diversa dall'importo.

---

## 4. Dove vive

**Nuovi**

- `database/migrations/2026_09_04_120000_add_fee_levers_to_all_fees.php`
- `database/sql/2026_09_04_quote_leve_comuni.sql` — **da eseguire prima del codice**
- `app/Http/Controllers/QuoteAdminController.php` — non scrive niente: descrive
  le tre quote in un array e apre la pagina
- `resources/views/admin/quote.blade.php` + `admin/quote/impostazioni.blade.php`,
  `admin/quote/pagamenti.blade.php`, `admin/quote/trattamento.blade.php`
- `tests/Feature/QuoteAdminTest.php` — 18 test

**Toccati**

- `FeeDefinition` — guadagna i nomi delle due leve (impostazione + colonna di
  ripiego, per ciascuna) e **perde `emitsKyInEuro`**: non e' piu' un fatto
  scritto nel codice ma una cifra che l'admin decide, e zero e' semplicemente il
  valore che rende la quota un puro incasso. Il flag e' stato tolto invece che
  lasciato a mentire.
- `AbstractFeeService` — assorbe dalle tre sottoclassi `kyCreditFor()`,
  `kyAllowanceEnabledFor()`, `kyAllowanceFor()`, `settleEuroPayment()`,
  `euroSettlementBlocker()` e `setTreatment()`. **I tre servizi delle quote non
  hanno piu' un solo metodo sul denaro che li distingua**: restano i nomi, il
  ciclo di vita e, per le aziende, la restrizione su chi puo' avere un
  trattamento (`treatmentApplies()`).
- `SystemSetting` (4 chiavi + accessor), `User` (4 colonne di ripiego),
  i tre `*FeeController` (le due leve nel salvataggio, il trattamento sulle prime
  due, via l'`adminIndex` che non serve piu'), `routes/web.php`,
  `layouts/portal.blade.php` (una voce di menu al posto di tre),
  `admin/user-show.blade.php` (il riquadro del trattamento su tutte e tre),
  le pagine portale della quota privati e le tre notifiche che promettevano
  «l'equivalente in KY».

Le tre vecchie viste di backoffice sono in `_to_delete/viste_quote_2026_09_04/`
(`device_bash` non puo' cancellare file sul PC).

---

## 5. Verifica fatta

| | |
|---|---|
| `QuoteAdminTest` | 18 test, 67 asserzioni — verde |
| `RegistrationFeeTest` | 120 test — verde |
| `AgentCodeFeeTest` | 74 test — verde |
| `CompanyAccountFeeTest` | 34 test — verde |
| `AdminBackofficeTest`, `AdminAccountOwnerLimitsTest`, `AdminFeeControllerTest` | 33 test — verde |
| `MlmAgentContractGateTest`, `MlmAgentRequestFlowTest`, `MlmSettingsControllerTest` | 37 test — verde |
| PHPStan sui file delle quote | **29 errori prima, 27 adesso** (sono tutti lo stesso rumore preesistente: proprieta' Eloquent lette da un tipo `Model&FeePayment`) |

Due test esistenti sono stati aggiornati perche' aprivano le vecchie pagine di
backoffice: adesso una verifica il redirect e l'altra apre la scheda nuova.
