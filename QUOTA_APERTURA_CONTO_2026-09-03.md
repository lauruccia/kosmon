# La terza quota: apertura conto per le aziende

03/09/2026 — richiesta di Laura, implementata sul motore comune delle quote.
**Aggiornato il 04/09/2026**: cosa riceve l'azienda in cambio non e' piu' fisso,
lo decide l'admin con due leve (sezione 1bis).

> **STATO.** Codice scritto e test verdi (34 test nuovi). **Niente commit, come
> da regola.** In produzione va eseguito PRIMA l'SQL
> `database/sql/2026_09_03_company_account_fee.sql`, poi il codice. Dopo
> l'esecuzione la quota e' **spenta**: si accende da `/admin/quote-apertura-conto`.

---

## 1. Cosa fa, in una pagina

Chi si registra come **azienda** si vede mettere in carico una quota una tantum
— **600,00 €** l'importo di partenza — per il conto aziendale. L'admin imposta
importo e interruttore dal backoffice, esattamente come per le altre due quote.

Le quattro decisioni chiuse il 03/09, che sono poi tutto cio' che la distingue:

| | |
|---|---|
| **In euro si ricevono KY?** | **Lo decide l'admin** (04/09): quanti KY accreditare e' un campo del pannello, zero di partenza. Vedi 1bis. |
| **Cosa blocca?** | **Niente.** L'azienda che non ha saldato continua a pagare, incassare e vendere. Vede il banner e riceve un sollecito, e basta. |
| **Chi la deve?** | Solo le aziende che si registrano **da quando la quota e' accesa**. Le ~1.200 anagrafiche importate restano a `NULL`; l'admin puo' chiederla a una alla volta dalla scheda utente. |
| **Si puo' pagare in KY?** | Solo se l'admin accende il flag: nasce **spento**, ed e' l'unico default diverso dalle altre due quote. Chi paga in KY va sotto; se abbia anche il fido aggiuntivo lo decide l'admin (1bis). |

### La differenza che conta piu' di tutte

**Questa quota non ferma il conto.** E' l'unica delle tre. Chi domani copiera'
una riga dal middleware delle altre due per "completare" questa, fermera'
milleduecento conti aziendali senza che nessuno lo abbia chiesto. Per questo
`EnsureRegistrationFeePaid` non la nomina, e c'e' un **test che sorveglia
proprio quel file** (`test_il_middleware_delle_quote_non_conosce_la_quota_di_apertura_conto`):
se la regola cambia, si cambia li', alla luce del sole.

La conseguenza pratica: il **banner** e il **sollecito per email** sono gli
unici due modi in cui il circuito chiede davvero quei 600 euro.

---

## 1bis. Le due leve (04/09/2026)

Cosa l'azienda riceve in cambio non e' scritto nel codice: sono **due leve
distinte**, ciascuna con un default nel pannello e un ripiego sulla singola
azienda, dalla sua scheda.

| | **Chi paga in EURO** | **Chi paga in KY** |
|---|---|---|
| Cosa riceve | **N KY sul conto**, N deciso dall'admin | **niente**: paga andando sotto |
| La leva | `company_account_fee_ky_credit_cents` | `company_account_fee_ky_allowance` |
| Di partenza | **0** — nessun KY finche' non lo scrive qualcuno | **acceso** — come nelle altre due quote |
| Se spenta / a zero | il circuito incassa e basta | la quota si mangia il fido che l'azienda ha gia'; senza fido proprio non riesce a pagare in KY, ed e' voluto |
| Ripiego sulla singola | `users.company_account_fee_ky_credit_override_cents` | `users.company_account_fee_ky_allowance_override` |

**Le due leve non si incrociano**: chi paga in KY non riceve nessun accredito,
chi paga in euro non riceve nessun fido.

**NULL non e' zero, e non e' `false`.** Sul ripiego della singola azienda NULL
vuol dire «segui il pannello»; 0 e «no» vogliono dire «per questa azienda ho
deciso cosi'», e restano fermi anche se domani il default cambia. E' l'unico
motivo per cui esistono due colonne invece di una, e c'e' un test che lo
sorveglia.

**L'accredito non e' legato alla quota.** Puo' essere piu' basso, uguale o piu'
alto: e' una decisione commerciale, non un resto. Quello che si da' e' moneta
coniata dal circuito, una volta per ogni azienda che paga.

**L'importo accreditato e' quello del giorno del saldo**, non uno scatto
congelato alla registrazione come la quota: la quota e' un debito che l'azienda
si e' assunta a una certa cifra, l'accredito e' una decisione del circuito e
vale quella in vigore quando i soldi arrivano. Quanto sia stato dato resta
scritto nel movimento (`company_account_fee_credit`) e nell'audit log.

**Cambiare il trattamento a saldo avvenuto non disfa niente**: i KY accreditati
e il fido concesso sono fatti, e si disfano annullando il pagamento. Per una
quota una tantum, cambiare dopo vuol dire in pratica non cambiare.

---

## 2. Cosa sapere prima di accendere il pagamento in KY

**Ogni conto nasce con un limite giornaliero di uscita di 500,00 KY**
(`Account::booted`, riga 149). Una quota da 600 KY **non ci passa**: l'azienda
preme "paga con il saldo KY", il motore rifiuta e lei legge «hai raggiunto il
limite giornaliero di uscita». Non e' un guasto — e' il motore che fa il suo
mestiere — ma vuol dire che, all'importo di oggi, il pagamento in KY funziona
solo per i conti a cui l'admin ha alzato quel limite.

Non l'hanno mai incontrato le altre due quote solo per una questione di
importo: 30 e 480 stanno sotto i 500. C'e' un test che inchioda il caso
(`test_un_limite_giornaliero_piu_basso_della_quota_impedisce_di_pagarla_in_ky`)
e un avviso nel pannello admin, accanto al flag.

**Se si vuole che i movimenti di quota saltino i limiti di spesa** — come gia'
saltano le commissioni (`TransactionFee::calculate`) — la modifica e' una
whitelist di `kind` in `TransferBookingService::assertTransferWithinLimits()`.
Non e' stata fatta: e' una modifica al motore dei soldi, e tocca a Laura
decidere se quella e' la regola.

---

## 3. Dove vive, file per file

**Nuovi**

| File | Cosa |
|---|---|
| `database/migrations/2026_09_03_120000_create_company_account_fee.php` | 3 colonne su `users`, 6 su `system_settings`, tabella `company_account_fee_payments` |
| `database/sql/2026_09_03_company_account_fee.sql` | l'equivalente per la produzione, in 5 blocchi, verifiche comprese |
| `app/Models/CompanyAccountFeePayment.php` | il tentativo di pagamento; causale bonifico `APERTURA-` |
| `app/Services/CompanyAccountFeeService.php` | la quota: `definition()`, `riguarda()`, `markDueOnRegistration()`, `requestFrom()`, `accountFor()`, e le due leve — `kyCreditFor()`, `kyAllowanceEnabledFor()`, `setTreatment()` |
| `app/Http/Controllers/CompanyAccountFeeController.php` | i quattro metodi di pagamento e il backoffice |
| `app/Notifications/CompanyAccountFee{Paid,Cancelled,Requested,Reminder}Notification.php` | le quattro mail |
| `resources/views/portal/company-account-fee{,-bank-transfer,-success}.blade.php` | le tre pagine utente |
| `resources/views/admin/company-account-fees.blade.php` | il pannello: impostazioni + elenco pagamenti |
| `tests/Feature/CompanyAccountFeeTest.php` | 23 test |

**Toccati**

| File | Cosa |
|---|---|
| `app/Models/User.php` | `$fillable` + cast della data + le due colonne del ripiego |
| `app/Services/Fees/AbstractFeeService.php` | **unico punto toccato nel motore comune**: il fido concesso a chi paga in KY passa da un hook (`kyAllowanceFor()`) che di default resta l'intero importo, cioe' il comportamento di sempre per le altre due quote |
| `app/Models/SystemSetting.php` | 6 chiavi, cast, default (600,00 e KY spento), i 3 accessor |
| `app/Models/Account.php` | `massimale()`: il terzo fido aggiuntivo si somma |
| `app/Services/TransferBookingService.php` | stessa somma nel punto che il motore fa rispettare davvero |
| `app/Models/TransactionFee.php` | i 3 `kind` nuovi esenti da commissione — **e `agent_code_fee_reversal`, che mancava dal 31/08** |
| `app/Http/Controllers/AdminController.php` | i movimenti della quota non si eliminano da /admin/movimenti |
| `app/Http/Controllers/AuthController.php` | il debito nasce alla registrazione dell'azienda |
| `app/Http/Controllers/KyCardController.php` | **quinto** incasso sul webhook Stripe |
| `routes/web.php` | 8 rotte portale + 7 admin |
| `routes/console.php` | commento: i due comandi notturni ora coprono tre quote |
| `app/Console/Commands/ExpireRegistrationFeeAttempts.php` | terza tabella nella scadenza dei tentativi |
| `app/Console/Commands/RemindRegistrationFees.php` | **generalizzato**: itera su un elenco di quote invece di conoscerne una sola |
| `resources/views/layouts/portal.blade.php` | voce di menu admin + terzo ramo del banner |
| `resources/views/admin/user-show.blade.php` | riquadro nella scheda dell'azienda |

### Il sollecito, e perche' il comando si chiama ancora cosi'

`quote:solleciti-iscrizione` adesso sollecita **due** quote (privati e apertura
conto; il codice agente resta fuori per scelta del 31/08). Il nome e' rimasto
quello: rinominarlo avrebbe voluto dire toccare la schedulazione su due server
in produzione per un'etichetta. Dentro, pero', non e' piu' una copia: l'elenco
delle quote sollecitate e' una tabella di sei campi, e aggiungerne una domani e'
una riga.

---

## 4. Chi e' "un'azienda"

Due condizioni **insieme**: `account_holder_type === 'company'` **e**
`company_id` valorizzato (`CompanyAccountFeeService::riguarda()`).

La seconda non e' pignoleria, evita due incidenti veri:

- gli **admin** nascono con `account_holder_type = 'company'` e `company_id`
  NULL (seeder e `ImportOldData`): con la sola prima condizione si vedrebbero
  chiedere 600 euro;
- i **collaboratori** invitati come sottoconto il campo non lo passano affatto e
  cadono sul default del database, che e' `'company'`. Stesso esito.

E la quota si segna **solo alla registrazione**, che e' la porta da cui passa il
titolare: chi viene aggiunto dopo a un'azienda gia' dentro non riapre nessun
conto e non paga niente.

---

## 5. Cosa resta aperto

- **Il pagamento in KY oltre i 500 KY giornalieri** (sezione 2): decisione di
  Laura, non fatta.
- **Il trattamento e' una decisione sola per azienda**, non uno storico: se un
  giorno servisse sapere «cosa le avevamo promesso a marzo», la risposta sta
  nell'audit log (`company_account_fee.treatment_set`), non in una colonna.
- **Un esonero** come quello della quota agente non c'e': se servira', lo zero
  in `company_account_fee_due_cents` e' il valore libero dove metterlo — ma va
  scritto, perche' oggi il motore comune legge lo zero come "niente da pagare"
  in tutte e tre le quote, e nelle altre due significa gia' due cose diverse
  (sospesa nei privati, esonerata negli agenti).
- **Le migliorie M2-M4** dell'analisi del 02/09 restano aperte, e questa terza
  quota le rende piu' evidenti: adesso i pannelli gemelli sono tre.
