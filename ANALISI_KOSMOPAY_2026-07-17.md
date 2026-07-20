# ANALISI STRATEGICA, BUSINESS PLAN E PIANO DI SVILUPPO — KOSMOPAY / KMONEY

**Data:** 17 luglio 2026
**Fonti:** codice del repository `kmoney-app` (analisi diretta dei file citati nel testo), sito pubblico https://kosmopay.it/, contratto di adesione (testo di default in `app/Models/SystemSetting.php`), `MLM_PROPOSAL.md`, ricerca web del 17/07/2026.
**Convenzione:** ogni affermazione è classificata come **[FATTO]** (verificato nel codice o sul sito), **[DICHIARATO]** (affermato ma non verificabile), **[IPOTESI]** (assunzione di scenario, sempre esplicitata) o **[INFORMAZIONE DA DEFINIRE]**.

---

# 1. EXECUTIVE SUMMARY

KosmoPay/KMoney è oggi **due progetti diversi che convivono nella stessa piattaforma**, e la prima decisione strategica è riconoscerlo:

1. **Ciò che il sito e il contratto descrivono [FATTO]:** un circuito di credito commerciale B2B in stile Sardex. KMoney è "unità di conto, il cui valore è pari ad un euro", non convertibile, scambiata tra aziende con percentuali di compensazione 0/25/50/75/100, fido concesso dal gestore, ricavi da "canone fisso annuale anticipato e compenso in percentuale sul venduto" (Art. 3 del contratto).
2. **Ciò che il brief di questa analisi propone [DICHIARATO]:** un modello B2C "ricarichi 100 € e ricevi 125 Ky", cioè un wallet consumer con bonus del 25% spendibile in una rete locale.

La piattaforma tecnica supporta già entrambi: le **KY Card** (`app/Models/KyCard.php`) hanno un bonus configurabile fisso o percentuale, l'accredito avviene per emissione dalla Cassa Circuito, e il motore contabile a partita doppia è solido, testato (670/670 test verdi) e ben oltre lo standard dei progetti in questa fase. **La tecnica non è il problema. Il modello economico del 25% sì**, finché non viene definito chi lo sostiene.

**Il punto centrale dell'analisi** (sviluppato in §6): quando un cliente versa 100 € e riceve 125 Ky, KosmoPay incassa 100 € in euro e crea 125 Ky di potere d'acquisto **che solo le attività aderenti possono onorare con merce e servizi reali**. Poiché i Ky non sono convertibili in euro [FATTO, Art. 5 del contratto], il costo del bonus non è "il 25%": se gli euro incassati non rientrano nel circuito, le attività in aggregato finanziano **il 100% del controvalore** (cedono 125 di valore in cambio di potere d'acquisto interno). Il modello regge solo a tre condizioni: (a) le attività riescono a rispendere i Ky dentro la rete con utilità reale; (b) parte degli euro incassati rientra nel circuito (acquisti del gestore presso gli aderenti, o riscatto parziale); (c) l'emissione bonus è governata da limiti (percentuali di accettazione, tetti, scadenze) — limiti che il codice in gran parte **già prevede**.

**Raccomandazione sintetica: TEST PILOTA (GO CON CONDIZIONI).** Il progetto è tecnicamente pronto e commercialmente interessante, ma il lancio pubblico del bonus 25% senza (1) un meccanismo di riassorbimento dei Ky, (2) un modello di ricavo attivo (fee incasso + canoni), (3) la verifica legale della qualificazione di KMoney e del programma MLM (L. 173/2005), sarebbe una scommessa sulla fiducia delle prime attività aderenti. Le condizioni precise sono in §27.

---

# 2. SPIEGAZIONE SEMPLICE DI KOSMOPAY

## 2.1 Versione in una frase (≤25 parole)

> Ricarichi il conto KosmoPay e ottieni il 25% in più da spendere nei negozi e nelle imprese del circuito vicino a te.

## 2.2 Versione semplice (≤100 parole)

> KosmoPay è un circuito di attività locali che si sono messe in rete. Quando ricarichi il tuo conto — per esempio 100 euro — ricevi 125 KMoney, una moneta di circuito che vale come l'euro ma si spende solo nelle attività aderenti: il bar, la palestra, l'officina, il parrucchiere della tua zona. Tu aumenti subito il tuo potere d'acquisto del 25%; le attività ottengono nuovi clienti senza spendere in pubblicità e rispendono a loro volta i KMoney incassati presso le altre attività della rete. Più la rete cresce, più conviene a tutti.

*Nota di compliance: la frase "vale come l'euro" va sempre accompagnata dalle condizioni (non convertibile, spendibile solo nel circuito, percentuale di accettazione decisa da ogni attività) — vedi §15.*

## 2.3 Elevator pitch (~30 secondi)

> "Le attività locali hanno due problemi: clienti che mancano e capacità invenduta — il tavolo vuoto del martedì, l'ora buca del parrucchiere. KosmoPay li risolve insieme: i clienti ricaricano euro e ricevono il 25% in più in KMoney, spendibili solo nella rete locale. Il bonus porta clienti nuovi alle attività; le attività recuperano il valore rispendendo i KMoney tra loro, da fornitori e colleghi del circuito. Noi guadagnamo da canoni e commissioni sui pagamenti. È il modello dei circuiti di credito commerciale — come Sardex — ma aperto anche ai consumatori, con un incentivo che si spiega in una riga: ricarichi 100, spendi 125."

## 2.4 Presentazione di due minuti (per un'attività commerciale)

> "Le faccio una domanda: quanto le costa oggi acquisire un cliente nuovo? Tra Google, i social e i volantini, per un'attività come la sua si spendono 5–15 € a contatto, senza garanzia che compri. KosmoPay funziona al contrario: **lei paga solo quando il cliente ha già comprato**, e paga in un modo particolare — accettando una parte del pagamento in KMoney, la moneta del circuito.
>
> Funziona così: i clienti della zona ricaricano il conto in euro e ricevono il 25% in più in KMoney. Quei KMoney si possono spendere **solo** nelle attività aderenti, quindi quei clienti cercano attivamente dove spenderli — e la trovano sulla mappa del circuito, in evidenza. Lei decide quanto accettarne: il 25%, il 50% o il 100% del prezzo, e può cambiare quando vuole, anche per singolo prodotto o solo nei giorni in cui ha meno lavoro.
>
> I KMoney che incassa valgono uno a uno con l'euro dentro il circuito: li usa per pagare fornitori aderenti, altre attività, servizi — la grafica, la manutenzione, il pranzo di lavoro. Il valore che 'sconta' al cliente lo recupera due volte: come margine sui clienti nuovi che non avrebbe avuto, e come potere d'acquisto verso la rete.
>
> Per iniziare non serve nulla: un QR code alla cassa, il telefono, e la sua vetrina nel portale. I primi tre mesi sono di prova: guardiamo insieme quanti clienti nuovi le ha portato il circuito e quanti KMoney ha rispeso. Se i numeri non le tornano, esce senza penali."

## 2.5 Spiegazione per il cliente

- **Quanto ricarichi:** scegli tu il taglio (es. 50, 100, 200 €), con carta, PayPal o bonifico. [FATTO: metodi supportati in `KyCardController.php`]
- **Quanto ricevi:** l'importo in KMoney più il bonus della card scelta (es. 100 € → 125 Ky). 1 Ky vale 1 € negli acquisti dentro il circuito. [FATTO: bonus per card configurabile; l'entità effettiva del 25% su tutte le card è [INFORMAZIONE DA DEFINIRE]]
- **Dove li spendi:** in tutte le attività aderenti — le trovi nella directory del portale, con l'indicazione di quanta parte del prezzo ciascuna accetta in KMoney (badge "Kmoney 25/50/75/100%"). [FATTO: directory e badge già implementati]
- **Limiti:** i KMoney non si convertono in euro e non si prelevano; ogni attività decide la percentuale del prezzo pagabile in Ky; possono esserci limiti per operazione impostati dal circuito. [FATTO: Art. 5 contratto; `accepted_ky_percentage`; `per_movement_limit`]
- **Perché conviene:** ogni ricarica aumenta il tuo potere d'acquisto del 25% su spese che faresti comunque (mangiare fuori, parrucchiere, palestra, manutenzioni), presso attività della tua zona.

## 2.6 Spiegazione per l'attività

- **Perché accettare KMoney:** clienti nuovi e ricorrenti che ti scelgono *perché* accetti KMoney; visibilità nella directory (chi accetta di più compare più in alto [FATTO: ordinamento per % implementato]); zero costo di acquisizione anticipato.
- **Come recuperi il valore:** (1) margine sui clienti incrementali, (2) rispesa dei Ky presso fornitori e attività del circuito, (3) uso dei Ky per acquisti che avresti fatto in euro.
- **Come usi i Ky incassati:** pagamenti ad altre attività aderenti (fornitori, servizi, welfare interno), acquisti nel marketplace del circuito.
- **Quali clienti acquisisci:** i titolari di conto KMoney della zona, motivati a spendere il saldo (e il bonus) entro la rete.
- **Costi:** canone annuale + percentuale sul venduto previsti dal contratto [FATTO: Art. 3; importi effettivi [INFORMAZIONE DA DEFINIRE]]; eventuale commissione per transazione se configurata [FATTO: motore fee presente, valori attivi [INFORMAZIONE DA DEFINIRE]].
- **Ritorno atteso:** dipende da margine e capacità inutilizzata — vedi §12 per categoria.

## 2.7 Esempio pratico completo

**Ipotesi dichiarate:** bonus 25% sulla ricarica; fee di circuito 3% sull'incassato in Ky a carico dell'attività; canoni esclusi dal calcolo; tutte le attività accettano 100% Ky su questi acquisti.

Giulia ricarica **100 €** → riceve **125 Ky**. In un mese li spende così:

| Acquisto | Attività | Prezzo | Pagato in Ky |
|---|---|---:|---:|
| Cena per due | Pizzeria Da Marco | 40 | 40 Ky |
| Taglio e piega | Parrucchiere Luce | 35 | 35 Ky |
| Tagliando bici | Officina 2Ruote | 50 | 50 Ky |

- **Giulia** ha ottenuto 125 € di beni/servizi pagandone 100 → **+25% di potere d'acquisto**.
- **Pizzeria Da Marco** incassa 40 Ky (fee 3% = 1,2 Ky): con food cost ~30%, il costo vivo della cena è ~12 €; usa i 38,8 Ky netti per pagare la manutenzione del locale a Officina 2Ruote → ha convertito un tavolo del martedì in un cliente nuovo e in potere d'acquisto.
- **Parrucchiere Luce** incassa 35 Ky (costo marginale di un'ora già in agenda: quasi nullo) e li spende in cene aziendali da Marco.
- **Officina 2Ruote** incassa 50 Ky + 38,8 Ky dalla pizzeria; ne spende una parte e accumula il resto: è il soggetto da monitorare (rischio accumulo, §6/§10).
- **KosmoPay** ha incassato: 100 € cash dalla ricarica **a fronte di 125 Ky emessi** (passività di circuito), più 3,75 Ky di fee (3% di 125). Il flusso euro è positivo oggi; l'equilibrio dipende da quanta parte dei 125 Ky verrà riassorbita (fee, canoni pagabili in Ky, spesa del gestore nel circuito) e da quanti resteranno inattivi (breakage). **Questo è il cuore dell'analisi: vedi §6.**

---

# 3. FUNZIONAMENTO RILEVATO (dal codice e dal sito)

## 3.1 Fatti osservati nel codice

| # | Fatto | Evidenza |
|---|---|---|
| F1 | KMoney è unità di conto con valore dichiarato pari a 1 €, **non convertibile** in euro; alla cessazione, la provvista non usata per 1 anno si intende rinunciata | Contratto di adesione, Art. 5 e 13 (`SystemSetting::defaultContractText()`) |
| F2 | Circuito contabile **chiuso**: la somma di tutti i saldi deve essere 0; il saldo negativo della "Cassa Circuito" = KY in circolazione | `Admin/EmissionController.php` (righe 24–29) |
| F3 | Ogni movimento genera **partita doppia** (2 `LedgerEntry`), con idempotency key, lock pessimistici, audit log | `TransferBookingService.php`, `KyCardController::creditKy()` |
| F4 | Le ricariche avvengono con **KY Card** a taglio fisso: prezzo in €, KY base + bonus **fisso o percentuale**, configurabile per card; pagamento Stripe, PayPal o bonifico (conferma manuale admin) | `KyCard.php`, `KyCardPurchase.php`, `KyCardController.php` |
| F5 | Gli euro delle ricariche vanno all'emittente (Stripe/PayPal/bonifico intestati al gestore); i KY (base+bonus) sono **emessi dalla Cassa Circuito** verso il conto del cliente | `KyCardController::creditKy()` |
| F6 | Ogni azienda dichiara la **% di prezzo accettata in KMoney** (0/25/50/75/100), anche per singolo prodotto; badge e ordinamento in directory; conto in negativo → obbligo 100% | `Company.php` (`ACCEPTED_KY_PERCENTAGES`, `computeEffectiveKyPercentage()`), `Account::allowedKyPercentages()` |
| F7 | Esistono **tetto massimo di saldo** (`max_balance`: sopra → vendita bloccata), **fido** (`CreditLimit`), limiti giornalieri/mensili/per movimento | `Account.php`, `SystemSetting::userLimitDefaults()` (fallback 2.000 KY per movimento) |
| F8 | **Commissioni configurabili** per tipo operazione (percentuale o flat, min/max), addebitate al pagante e accreditate alla Cassa; cashback mai commissionato | `TransactionFee.php`, `TransferBookingService::bookFee()` |
| F9 | Il contratto prevede ricavi da **canone fisso annuale + % sul venduto** | Art. 3 del contratto |
| F10 | Motore **cashback** con regole targeting (attivo dal conto sistema), **welcome bonus** configurabile (default 0) | `CashbackService.php`, `CashbackRule.php`, `SystemSetting` |
| F11 | Programma **MLM/agenti "KNM"** completo: punti sui depositi (min 120 €, spalmati 12–36 mesi), commissioni dirette fino al 40% e indirette (4/2/1/0,5/8%, 0,5% dal 6° livello) calcolate su "Prov K" = 30% del deposito mensile; bonus struttura 60–200 € settimanali; bonus una tantum 200–900 €; extra bonus promozione 300–20.000 €. **Pagati in euro reali, fuori dal circuito KY** | `MLM_PROPOSAL.md`, `MlmCommissionEngine.php`, `SystemSetting::MLM_KNM_MARGIN_DEFAULT_PERCENT` |
| F12 | Sicurezza: 2FA TOTP, Passkey/WebAuthn, step-up auth, PIN pagamento, middleware onboarding/contratto/sospensione, reconciliation job (`VerifyAccountingIntegrity`), health check | struttura `app/Http/Middleware`, `Console/Commands` |
| F13 | Funzionalità già presenti: QR incasso, NFC (smartphone e card fisica), pagamenti via link/codice/Sonic, richieste di pagamento, rate, netting, pagamenti programmati, sottoconti, API v1 + webhook + OpenAPI, marketplace/listing, PDF estratto conto, notifiche push | `routes/web.php`, controller dedicati |
| F14 | Il sito pubblico si presenta come "moneta complementare trasversale del Gruppo Kosmos" **B2B**; homepage: "5+ aziende nel circuito, 21+ transazioni totali"; **nessuna menzione del bonus 25%** | https://kosmopay.it/ (17/07/2026) |
| F15 | Incoerenza societaria da sanare: footer del sito = **KNM S.R.L., P.IVA 13273091002, Roma**; contratto di default = **Kosmos S.r.l., Camerino (MC), P.IVA 01768560433**, Foro di Macerata | sito + `SystemSetting::defaultContractText()` |
| F16 | FAQ del sito: apertura conto gratuita, KY spendibili solo nel circuito, "senza commissioni nascoste", fido su richiesta, API disponibili | https://kosmopay.it/#faq |

## 3.2 Informazioni dichiarate (dal brief, non riscontrate nel codice/sito)

- Il bonus è del 25% per ogni ricarica ("100 € → 125 Ky"). Nel codice il bonus è **per card e configurabile**; quali card esistano in produzione e con che bonus è [INFORMAZIONE DA DEFINIRE].
- L'obiettivo è una rete territoriale di prossimità B2C. Il contratto e il sito attuali descrivono un circuito B2B tra imprese.

## 3.3 Flusso reale ricostruito (diagramma testuale)

```
[1] REGISTRAZIONE CLIENTE                    [2] REGISTRAZIONE ATTIVITÀ
    email+password o Passkey                     idem + dati azienda (P.IVA, settore)
    → verifica email → 2FA (opz.)               → stesso funnel
        │                                            │
[3] ONBOARDING (3 step)                          [3'] KYC azienda: upload documenti
    profilo → KYC → attesa approvazione              → revisione admin (24-48h)
        │                                            → firma contratto con OTP
        ▼                                            → eventuale fido (CreditLimit)
[4] RICARICA IN EURO                                 → piano (ecommerce/vetrina/…)
    scelta KY Card (prezzo €, KY base, bonus)        → % Kmoney accettata (0-100)
    Stripe | PayPal | bonifico (conferma admin)
        │  euro → conto corrente del gestore
        ▼
[5-6] ACCREDITO: Cassa Circuito ──125 Ky──▶ conto cliente
      (transfer kind=kycard_topup, partita doppia, idempotente)
      + punti MLM al network dell'invitante (in EUR, fuori circuito)
        │
[7] PAGAMENTO PRESSO L'ATTIVITÀ
    QR / NFC / link / codice / richiesta di pagamento
    quota Ky ≤ % accettata dall'attività; eventuale resto in € fuori piattaforma
        │
[8] L'ATTIVITÀ RICEVE I KY  (conto azienda +Ky)
    − eventuale fee di circuito → Cassa      − cashback eventuale ← Cassa
        │
[9] RIUTILIZZO: paga fornitori/attività del circuito, rate, netting,
    pagamenti programmati; se saldo ≥ max_balance → non può più vendere
    se saldo < 0 (fido) → obbligo vendite al 100% Ky
        │
[10-11] COMPENSAZIONE (NettingProposal) / CONVERSIONE: **non prevista** (Art. 5)
[12] STORNO/RIMBORSO: refundMerchant / nota di credito (transfer inverso tracciato)
[13-14] CHIUSURA/USCITA: saldo negativo → pareggio in € verso il gestore (Art. 9);
        saldo positivo → resta credito di fornitura, si prescrive dopo 1 anno (Art. 13)
```

---

# 4. INFORMAZIONI MANCANTI ([INFORMAZIONE DA DEFINIRE])

Queste rispondono alla checklist del brief; dove il codice dà una risposta parziale, è indicata.

| # | Domanda | Stato |
|---|---|---|
| M1 | Chi incassa i 100 €? | Parziale [FATTO]: il beneficiario di Stripe/PayPal/IBAN configurati (`config/kmoney.php`, `services.stripe`). **Quale società esattamente (KNM S.r.l. o Kosmos S.r.l.) e con quale contabilizzazione (ricavo? debito verso il circuito?) è da definire.** |
| M2 | Le card attive in produzione: tagli, bonus effettivi, quante prime ricariche | Da estrarre dal DB di produzione |
| M3 | Valori attivi di canone annuale e % sul venduto (Art. 3) per piano | Da definire/estrarre |
| M4 | Fee di transazione attive (tabella `transaction_fees` in prod) | Da estrarre |
| M5 | Scadenza dei Ky in costanza di rapporto (il contratto prevede prescrizione solo post-uscita) | Da definire (oggi: nessuna scadenza) |
| M6 | Impegno del gestore a re-immettere valore nel circuito (Art. 9 lo prevede solo per i pareggi in denaro) | Da definire — **cruciale per §6** |
| M7 | Trasferibilità cliente→cliente dei Ky (il motore la consente tecnicamente; policy?) | Da definire |
| M8 | Politica rimborsi della ricarica in € (diritto di recesso e-commerce sulle KY Card?) | Da definire con legale |
| M9 | Cosa succede ai Ky dei clienti se un'attività chiave esce dal circuito | Da definire (oggi: nessuna tutela specifica) |
| M10 | Chi copre insolvenze sui fidi (saldi negativi non pareggiati) | Contratto: il cliente deve pareggiare in €; enforcement reale da definire |
| M11 | Budget disponibile per il pilota e per il finanziamento del bonus | Da definire |
| M12 | Stato della verifica legale su qualificazione KMoney e su L. 173/2005 per il programma KNM | Segnalata in `MLM_PROPOSAL.md` §7.7, **mai eseguita** [FATTO da memoria progetto] |

---

# 5. VANTAGGI PER CLIENTI E ATTIVITÀ (sintesi validata)

**Cliente:** +25% di potere d'acquisto immediato su spese locali; pagamenti moderni (QR/NFC/link); scoperta di attività della zona; nessun costo di conto [FATTO: FAQ].
**Attività:** acquisizione clienti pay-per-result; visibilità in directory proporzionale alla % accettata [FATTO]; monetizzazione della capacità invenduta; rete B2B di fornitori/colleghi; strumenti da "banca di circuito" (fido, rate, netting, API) che i competitor loyalty non hanno.
**KosmoPay:** float in euro delle ricariche; breakage; fee e canoni; posizione di gestore di rete con dati transazionali; piattaforma già multi-tenant-izzabile (branding configurabile [FATTO: `SystemSetting::branding()`]) per white label.

I vantaggi sono reali **solo se** la rete raggiunge la densità minima (§10) e il riassorbimento dei Ky funziona (§6). In una rete da "5+ aziende e 21+ transazioni" [FATTO: homepage] il bonus 25% non è oggi difendibile pubblicamente: il cliente non avrebbe dove spendere.

---

# 6. CRITICITÀ DEL BONUS DEL 25% — L'ANALISI CENTRALE

## 6.1 La meccanica contabile reale [FATTO]

Per ogni ricarica da 100 €:

```
Cliente:          −100 €        +125 Ky
Gestore (cash):   +100 €        (su c/c bancario)
Cassa Circuito:                 −125 Ky   (passività di circuito: KY in circolazione)
```

Il "costo del bonus" **non è un costo cash per nessuno al momento dell'emissione**. Diventa un costo reale solo quando i 125 Ky vengono spesi: a quel punto le attività cedono 125 € di beni/servizi e ricevono 125 Ky. Da qui tre verità scomode che il modello deve governare:

1. **Se il gestore trattiene i 100 € fuori dal circuito**, le attività in aggregato hanno finanziato il 100% del controvalore (125), non il 25%: hanno convertito merce reale in potere d'acquisto interno. Il sistema è onesto solo se quel potere d'acquisto interno è realmente spendibile con utilità comparabile — cioè se la rete è densa e bilanciata.
2. **Il vero sussidio del 25% è a carico di chi assorbe Ky senza riuscire a rispenderli** (tipicamente le attività con più vendite in Ky e meno fornitori nel circuito). Il rischio non è distribuito uniformemente: si concentra sui "merchant magnete".
3. **Il gestore ha due valvole per rendere il sistema sostenibile:** (a) riassorbire Ky (fee in Ky, canoni pagabili in Ky, acquisti del gestore presso gli aderenti — che può permettersi proprio grazie ai 100 € incassati); (b) limitare l'emissione netta (bonus solo su prima ricarica, tetti, scadenze, % di accettazione). Il contratto già contiene il principio giusto all'Art. 9: a fronte di somme ricevute, Kosmos "provvederà ad immettere nel Circuito prodotti e servizi per un valore equivalente" — oggi limitato ai pareggi debitori; **estenderlo alle ricariche è la chiave del modello consigliato (§27)**.

Parametri che decidono la sostenibilità: **R** = tasso di redemption dei Ky (quota spesa entro 12 mesi), **α** = quota degli euro incassati re-immessa nel circuito dal gestore, **f** = fee media sul transato, **β** = bonus medio effettivo. Condizione di equilibrio percepito dalle attività: i Ky in ingresso devono trovare uscite (fornitori, canoni in Ky, acquisti del gestore) per almeno il 60–70% entro 6 mesi, altrimenti la % di accettazione dichiarata crolla e con essa la promessa al cliente.

## 6.2 Modelli alternativi

### Modello A — Bonus interamente a carico delle attività
Il 25% come sconto commerciale implicito. Margine lordo minimo per non perdere: se un'attività incassa 100% Ky e non li rispende, serve margine ≥ 20% (125 incassati per 100 di prezzo pieno → sconto effettivo 20% sul valore erogato); se rispende i Ky all'80% di utilità, il costo scende a ~4–8%. **Adatte:** ristorazione (margine lordo 60–70%), servizi alla persona (70–90%), palestre/corsi (capacità invenduta), professionisti. **Inadatte:** alimentari e retail a margine 15–30%, farmacie, carburanti. **Rischio:** accumulo sui magneti; sostenibile solo con reciprocità di spesa alta.

### Modello B — Bonus interamente a carico di KosmoPay
Il gestore "copre" il bonus impegnandosi a riscattare o riassorbire i 25 Ky extra (acquisti nel circuito o riscatto in € alle attività). Costo cash: 25 € per ricarica da 100 € = **CAC di 25 € a cliente ricaricante** (in linea con i CAC fintech consumer, 30–80 €). Con 1.000 clienti × 100 € = 100.000 € incassati e 25.000 € di impegno: sostenibile come **promozione a budget** (es. primi 6 mesi, prima ricarica soltanto), non come regime permanente senza ricavi robusti.

### Modello C — Bonus condiviso
Ripartizione tipo: attività 10–15 punti (sconto implicito sostenibile per categorie ad alto margine), KosmoPay 5–10 punti (budget marketing dai ricavi fee/canoni), partner/sponsor locali 0–5 punti (es. consorzi, Comuni con fondi per il commercio di prossimità). È il modello di regime più realistico, **ma va reso invisibile al cliente**: lui vede sempre "+25%".

### Modello D — Bonus con limiti d'uso ⭐ (in gran parte già implementato)
125 Ky pieni, ma: % massima per acquisto decisa dall'attività (0/25/50/75/100 — [FATTO]); tetti per movimento ([FATTO: `per_movement_limit`]); possibilità di limitare il bonus a categorie/giorni ([DA SVILUPPARE]); scadenza del solo bonus (es. 12 mesi — [DA SVILUPPARE]); spesa minima. Riduce la velocità di uscita dei Ky bonus e distribuisce il costo. Attrattiva quasi intatta se comunicata bene ("125 Ky, e ogni attività ti dice quanto ne accetta").

### Modello E — Cashback differito
Il cliente riceve 100 Ky subito e matura il 25% come cashback sugli acquisti (motore già presente [FATTO: `CashbackService`]). Pro: il bonus è pagato solo su spesa reale, autofinanziato dalla fee; niente stock di Ky bonus dormienti. Contro: il claim "ricarichi 100 e hai 125" si indebolisce ("fino al 25% di cashback"), meno esplosivo in acquisizione. Ottimo come **regime post-promozione**.

### Modello F — Bonus variabile
5–10% permanente su ogni ricarica + 25% solo su prima ricarica/campagne/card alto taglio ([FATTO: già possibile per card]); bonus maggiorato dalle attività che vogliono spingersi (finanziato da loro come promo mirata [DA SVILUPPARE: bonus sponsorizzato per attività]).

## 6.3 Tabella comparativa

| Modello | Vantaggio cliente | Costo attività | Costo KosmoPay | Rischio | Sostenibilità | Attrattiva |
|---|---:|---:|---:|---|---|---|
| A — tutto su attività | 25% | 8–20% del transato | ~0 | Accumulo Ky, abbandono merchant | Bassa da sola | Alta |
| B — tutto su KosmoPay | 25% | ~0 | 25 €/ricarica 100 € | Cassa; promo-dipendenza | Solo a tempo | Altissima |
| C — condiviso | 25% | 10–15% | 5–10% | Complessità accordi | **Media-alta** | Alta |
| D — con limiti | 25% (con regole) | 5–12% | 2–5% | Percezione "asterischi" | **Alta** | Medio-alta |
| E — cashback differito | fino a 25% | 3–8% | autofinanziato | Minor wow in acquisizione | **Molto alta** | Media |
| F — variabile | 10–25% | variabile | a budget | Complessità comunicativa | Alta | Alta |

**Conclusione §6:** il 25% è sostenibile **solo come combinazione D+C+F**: bonus pieno sulla prima ricarica (costo di acquisizione a budget, Modello B limitato), bonus di regime 5–10% (F), limiti d'uso e scadenza del bonus (D), costo condiviso con le attività ad alto margine via fee/canoni (C), e impegno formale del gestore a re-immettere nel circuito una quota α ≥ 30–50% degli euro incassati finché la rete non è matura. Le condizioni numeriche sono in §7 e §27.

---

# 7. UNIT ECONOMICS

## 7.1 Parametri del modello (tutte [IPOTESI] modificabili; formule esplicite)

| Parametro | Prudente | Realistico | Ottimistico |
|---|---:|---:|---:|
| Ricarica media/anno per cliente attivo (R€) | 150 € | 300 € | 600 € |
| Clienti registrati → ricaricanti (att%) | 25% | 40% | 55% |
| Bonus medio effettivo (β) | 25% | 15% (25% 1ª ricarica, 10% dopo) | 12% |
| Redemption Ky entro 12 mesi (R) | 95% | 90% | 85% |
| Fee media sul transato Ky (f) | 2% | 3% | 4% |
| Canone medio attività/anno (C_a) | 0 € (pilota) | 240 € | 360 € |
| Quota € re-immessa nel circuito (α) | 50% | 35% | 25% |
| CAC cliente (oltre al bonus) | 8 € | 5 € | 3 € |
| CAC attività (commerciale) | 300 € | 200 € | 150 € |
| Costi fissi piattaforma/anno (hosting, Stripe ~1,5%+0,25, assistenza, sviluppo) | 60.000 € | 90.000 € | 120.000 € |

**Formule:**
- Volume ricariche = Clienti × att% × R€
- Ky emessi = Volume × (1+β) · Bonus emesso = Volume × β
- Ky spesi (fatturato lordo alle attività) = Ky emessi × R
- Ricavi fee = Ky spesi × f · Ricavi canoni = N_attività × C_a
- Impegno di riassorbimento = Volume × α (spesa del gestore nel circuito: non è un costo perso, è acquisto di beni/servizi, ma assorbe cassa)
- Breakage (Ky mai spesi) = Ky emessi × (1−R) → riduce la passività effettiva

## 7.2 Scenario REALISTICO alle cinque scale (valori annui, €)

| Scala (clienti / attività) | Volume ricariche | Ky emessi | Bonus emesso | Ky spesi | Fee (3%) | Canoni | **Ricavi KosmoPay** | CAC+fissi | **Margine ante-riassorbimento** |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 100 / 10 | 12.000 | 13.800 | 1.800 | 12.420 | 373 | 2.400 | **2.773** | ~92.500 | **−89.700** |
| 500 / 30 | 60.000 | 69.000 | 9.000 | 62.100 | 1.863 | 7.200 | **9.063** | ~98.500 | **−89.400** |
| 1.000 / 50 | 120.000 | 138.000 | 18.000 | 124.200 | 3.726 | 12.000 | **15.726** | ~105.000 | **−89.300** |
| 5.000 / 200 | 600.000 | 690.000 | 90.000 | 621.000 | 18.630 | 48.000 | **66.630** | ~155.000 | **−88.400** |
| 10.000 / 500 | 1.200.000 | 1.380.000 | 180.000 | 1.242.000 | 37.260 | 120.000 | **157.260** | ~240.000 | **−82.700** |

Lettura onesta: **con fee al 3% e canoni a 240 €, la piattaforma da sola non copre i costi fissi nemmeno a 10.000 clienti.** Il pareggio operativo richiede una (o più) di queste leve:
- **canone tipo Sardex** (i circuiti di credito commerciale applicano canoni 300–1.000 €+ /anno e ~1% sul transato B2B — vedi §14): a 500 attività × 600 € = 300.000 € → break-even;
- **fee 4–5% sull'incasso consumer** (accettabile: sostituisce budget marketing del merchant, va confrontata col CAC pubblicitario, non con le fee POS);
- **contenimento dei costi fissi** (oggi il progetto gira con sviluppo interno: lo scenario "fissi 90k" presume struttura; in modalità bootstrap reale i fissi possono stare sotto 30k → break-even già a ~4.000 clienti/200 attività);
- **ricavi B2B aggiuntivi** (white label, servizi premium, §13).

Il **fabbisogno di cassa** non coincide col margine: i 100 € incassati restano al gestore (float). A scala 5.000 clienti: +600.000 € di cassa in ingresso, −90.000 € bonus (non cash), impegno di riassorbimento α×600k = 210.000 € (spesa in beni nel circuito, in parte sostitutiva di costi che il gestore avrebbe comunque). **Il modello non muore di cassa: muore di fiducia dei merchant se i Ky non circolano.** La metrica da guardare non è il P&L del primo anno ma il **tasso di rispesa merchant** (§24).

## 7.3 Scenari prudente e ottimistico (sintesi a 5.000 clienti / 200 attività)

| | Prudente | Realistico | Ottimistico |
|---|---:|---:|---:|
| Volume ricariche | 187.500 | 600.000 | 1.650.000 |
| Bonus emesso | 46.875 | 90.000 | 198.000 |
| Ricavi (fee + canoni) | 4.453 + 0 | 18.630 + 48.000 | 62.832 + 72.000 |
| Margine (ricavi − CAC − fissi) | ≈ −155.500 | ≈ −88.400 | ≈ −30.200 |

Anche lo scenario ottimistico a questa scala resta negativo **se i costi fissi sono da struttura (120 k€)**: la redditività arriva o dalla scala (10.000+ clienti con canone medio ≥ 400 €) o dal contenimento dei fissi.

Break-even realistico: **~8.000–12.000 clienti attivi e 400–600 attività paganti** con fee 3–4% e canone medio ≥ 400 €; oppure **~4.000 clienti / 200 attività** in assetto bootstrap (fissi < 30k) con canone 300 €. In ogni scenario i **ricavi dalle attività (canoni) pesano più delle fee**: KosmoPay è prima di tutto un business B2B di rete, il bonus consumer è il motore di acquisizione.

---

# 8. ANALISI DI FATTIBILITÀ

**Commerciale — MEDIA.** Il vantaggio per l'attività si spiega bene ma richiede un venditore dal vivo (ciclo 2–6 settimane per adesione; i circuiti B2B usano broker dedicati — il progetto ha già il ruolo `broker` nel codice [FATTO]). Il 25% è molto attrattivo per il cliente; per l'attività lo è la promessa "clienti nuovi senza anticipo". Le categorie ad alto margine e capacità invenduta comprendono subito; il retail a basso margine no (va escluso o limitato al 25% di accettazione).

**Economica — CONDIZIONATA.** Vedi §7: sostenibile con bonus differenziato (25% solo 1ª ricarica), fee 3–4%, canoni B2B reali, α ≥ 30% e disciplina di emissione. Non sostenibile: 25% flat per sempre senza canoni.

**Tecnica — ALTA (già pronta).** Ledger a partita doppia, idempotenza, lock, audit, test 670/670, API, webhook, QR/NFC, rate, netting [FATTO]. Mancano solo: scadenza/vesting bonus, wallet consumer mobile-first, mappa, campagne per categoria/giorno (§20). Scalabilità: MySQL+Redis+Reverb adeguati fino a decine di migliaia di utenti; il collo di bottiglia noto è il lock sul conto sistema per fee/cashback (già documentato nel codice [FATTO: commento in `bookFee()`]).

**Operativa — MEDIA.** KYC manuale (24–48h) regge il pilota, non la scala; onboarding merchant richiede formazione (kit merchant già presente [FATTO: `MerchantKitController`]). Serve un playbook assistenza e un processo reclami (pagina prevista dal footer).

**Normativa — DA VERIFICARE PRIMA DEL LANCIO PUBBLICO (non conclusioni legali, aspetti da sottoporre a professionista abilitato):**
1. **Qualificazione di KMoney.** Il circuito B2B "in compensazione" tra imprese aderenti con unità di conto non convertibile è il modello Sardex, storicamente operato senza licenza IMEL in quanto circuito chiuso a spendibilità limitata. **Ma la ricarica consumer in euro con accredito di unità spendibili presso una rete di esercenti terzi avvicina il prodotto alla moneta elettronica** (fondi ricevuti a fronte di valore monetario memorizzato, accettato da soggetti diversi dall'emittente — direttiva EMD2/art. 114-bis TUB) o, in alternativa, alla disciplina dei **buoni-corrispettivo multiuso** (con effetti IVA diversi) o alla **limited network exclusion PSD2** (rete limitata di esercenti — richiede notifica a Banca d'Italia sopra soglie di volume). La differenza tra "voucher/buono", "credito commerciale" e "moneta elettronica" dipende esattamente dai dettagli oggi [DA DEFINIRE]: rimborsabilità, trasferibilità tra utenti, ampiezza della rete. Fonti di partenza: [Banca d'Italia — IMEL](https://www.bancaditalia.it/compiti/vigilanza/accesso-mercato/imel/index.html), [Diritto Bancario — elementi qualificanti della moneta elettronica](https://www.dirittobancario.it/art/gli-elementi-qualificanti-della-moneta-elettronica/), [monete complementari e liquidità](https://www.dirittodellinformatica.it/glossario/informatica/monete-complementari-virtuali-soluzioni-innovative-alla-luce-delle-esigenze-di-liquidita-delle-imprese.html/).
2. **Programma KNM (MLM).** Punti e bonus legati a depositi e reclutamento: da validare rispetto alla **L. 173/2005** (divieto di vendita piramidale quando il guadagno deriva prevalentemente dal reclutamento). Già segnalato in `MLM_PROPOSAL.md` §7.7 e mai chiuso [FATTO]. **È il rischio legale più concreto del progetto.**
3. **Comunicazione del bonus**: "ricevi il 25% in più" senza condizioni ben visibili → pratica commerciale scorretta potenziale (AGCM). Sempre accompagnare con limiti (§15).
4. **Antiriciclaggio**: soglie e adeguata verifica sulle ricariche (specie bonifici confermati a mano); pagina AML/KYC già prevista [FATTO].
5. **Coerenza contrattuale**: sanare la discrepanza KNM S.r.l. / Kosmos S.r.l. (F15) e il contratto pensato per imprese ma firmato anche da consumatori (KYP): clausole vessatorie, foro del consumatore, recesso.

---

# 9. MINIMUM VIABLE NETWORK (MVN)

Una rete è "viva" quando un cliente medio può spendere 125 Ky **in un mese normale senza sforzarsi**. Condizioni minime prima del lancio pubblico in una zona:

| Condizione | Soglia MVN |
|---|---|
| Attività attive (non solo registrate) | **≥ 30** in raggio 15 min |
| Categorie indispensabili coperte | ristorazione (≥5), bar/colazioni (≥3), servizi persona (≥4), alimentari/gastronomia (≥2), benessere/palestra (≥2), servizi casa/auto (≥3), professionisti (≥3) |
| Clienti ricaricanti | ≥ 300 (rapporto ~10:1 clienti/attività) |
| Copertura spesa quotidiana | un cliente deve poter spendere ≥ 60% di un paniere mensile tipo (pasti fuori, cura persona, tempo libero, manutenzioni) |
| Reciprocità B2B | ogni attività deve avere ≥ 2 fornitori/uscite possibili in rete (altrimenti accumula) |
| Tetto emissione | Ky in circolazione ≤ 1,5 × transato mensile della rete (governare con tagli card e bonus) |
| Anti-accumulo | max_balance impostato per ogni attività [FATTO: campo esistente] + monitor concentrazione (top-3 attività < 40% dei Ky) |

Raggio geografico consigliato: **un quartiere/città media (10–15 min)**, non una provincia. Meglio 30 attività in 2 km² che 100 sparse in 50 km.

---

# 10. PIANO PILOTA LOCALE (90 giorni)

**Fase 1 — Preparazione (settimane 1–3):** scelta zona (dove esistono già relazioni commerciali del Gruppo Kosmos); definizione regole pilota: bonus 25% **solo prima ricarica** (card dedicate — configurabili senza sviluppo [FATTO]), bonus 10% successive, fee 0% per i primi 90 giorni ai founding merchant, canone 0 nel pilota con listino firmato per il post-pilota; adeguamenti tecnici minimi (scadenza bonus, mappa — §20); materiali (vetrofania "Qui accetti KMoney +25%", kit QR già esistente [FATTO]); baseline metriche (§24).
**Fase 2 — Reclutamento attività (settimane 2–6):** 100 contattate → obiettivo 30 aderenti (25–35% di conversione con visita dal vivo è realistico per offerte a costo zero); esclusiva di categoria per i primi 3 mesi come leva ("un solo parrucchiere nel quartiere in lancio"); formazione 30 minuti in negozio; ogni attività riceve 50 Ky di credito demo.
**Fase 3 — Acquisizione clienti (settimane 4–10):** lancio solo quando MVN raggiunto; canali: vetrine dei merchant (il canale più efficace e gratuito), 2 eventi di quartiere, referral esistente [FATTO: `ReferralController`] con 10 Ky a invito andato a buon fine, social geo-localizzati (budget 1.500 €). Obiettivo: 500 registrati, 200 ricaricanti.
**Fase 4 — Utilizzo (settimane 6–12):** notifica "hai ancora X Ky" [FATTO: balance alert], promo del mercoledì (giorni deboli), sfida a timbri digitale tra attività.
**Fase 5 — Valutazione (settimana 13).** Criteri di successo: ≥200 clienti ricaricanti; ricarica media ≥ 80 €; ≥60% dei Ky spesi entro 60 giorni; ≥50% dei merchant con almeno 1 rispesa B2B; NPS merchant ≥ 30; ≥30% clienti con 3+ transazioni. Fallimento: redemption < 30%, accumulo top-3 > 60%, churn merchant > 20% → tornare al §6 e rivedere bonus/rete prima di scalare.

Budget pilota indicativo [IPOTESI]: 8–15 k€ (commerciale part-time, materiali, eventi, ads) + bonus emesso (~5.000 Ky su 200 ricariche da 100 €, costo effettivo differito secondo §6).

---

# 11. BUSINESS PLAN (sintesi operativa)

**Problema.** Clienti: potere d'acquisto eroso; nessun vantaggio a comprare locale. Attività: CAC pubblicitario crescente e inefficace, capacità invenduta, liquidità scarsa, dipendenza da piattaforme che prendono il 15–30% (delivery). Territorio: valore che esce dal quartiere verso e-commerce e GDO.
**Soluzione.** Circuito di pagamento locale con incentivo esplicito (+25%) che vincola la spesa al territorio, più infrastruttura da "banca di circuito" per le imprese (fido, rate, netting, API) già costruita [FATTO].
**Modello di business.** B2B-first: canoni attività (per piano: Anagrafica gratis → Ecommerce premium [FATTO: piani già esistenti]), fee 3–4% sull'incassato Ky, servizi premium (visibilità, campagne, CRM), white label per consorzi/CCN/Comuni. Il bonus consumer è CAC, non revenue.
**Mercato.** TAM Italia [IPOTESI motivate]: ~2,7 M di micro-imprese commercio/servizi; spesa consumer locale intercettabile enorme ma non è la metrica utile. SAM ragionevole: città medie e quartieri metropolitani dove il Gruppo Kosmos ha relazioni — migliaia di attività. SOM 36 mesi: 3–5 zone, 1.500 attività, 30.000 clienti. Benchmark: SardexPay ha costruito in Sardegna un circuito con migliaia di imprese e decine di milioni di € di transato annuo in crediti — dimostra che il modello B2B regge a scala regionale ([SardexPay](https://www.sardexpay.net/knowledge-base-web/about-kb-add-ons/), [analisi Innogest](https://www.innogestcapital.com/sardex-come-funziona-un-circuito-di-credito-complementare/)); nessuno in Italia ha ancora vinto il B2C locale con bonus — è lo spazio di posizionamento, ma anche il motivo per cui va testato.
**Strategia commerciale.** Broker/agenti territoriali (ruolo già nel codice; il programma KNM può diventare la rete vendita, **se sanato legalmente e riorientato: compensi legati al transato delle attività procurate, non ai depositi reclutati**). Prime attività: relazioni dirette + esclusiva di categoria. Associazioni di via, CCN e consorzi come moltiplicatori (offerta white label).
**Piano economico 12/24/36 mesi (scenario realistico bootstrap, fissi contenuti):**

| | 12 mesi | 24 mesi | 36 mesi |
|---|---:|---:|---:|
| Zone attive | 1 (pilota+consolidamento) | 3 | 5 |
| Attività paganti | 60 | 400 | 1.000 |
| Clienti ricaricanti | 1.000 | 8.000 | 25.000 |
| Volume ricariche | 250 k€ | 2,4 M€ | 7,5 M€ |
| Ricavi (fee+canoni+premium) | 30 k€ | 260 k€ | 750 k€ |
| Costi (team 2→5→8, marketing, infra) | 120 k€ | 380 k€ | 700 k€ |
| **EBITDA** | **−90 k€** | **−120 k€** | **≈ +50 k€** |
| Fabbisogno cumulato | | | **250–350 k€** |

Break-even mese 30–34 nello scenario realistico; il float delle ricariche riduce il fabbisogno di cassa apparente ma **non va contabilizzato come ricavo** (è passività di circuito).
**Rischi principali:** (1) normativo — qualificazione KMoney e L. 173/2005 sul KNM (mitigazione: parere legale prima del lancio, ridisegno compensi); (2) squilibrio Ky emessi/spendibili (mitigazione: MVN, tetti, α, monitor concentrazione); (3) adozione lenta merchant (mitigazione: pilota a costo zero, esclusiva); (4) reputazionale — un merchant che rifiuta i Ky "promessi" brucia la fiducia (mitigazione: % accettazione visibile e vincolante, badge [FATTO]); (5) chiave-uomo: sviluppo concentrato su una persona; (6) copia da parte di player più grandi (mitigazione: densità locale come barriera).

---

# 12. ATTIVITÀ IDEALI (analisi per categoria)

| Categoria | Potenziale | Frequenza | Margine | Facilità adesione | Rischio accumulo Ky | Priorità |
|---|---:|---:|---:|---:|---:|---:|
| Ristoranti/pizzerie | Alto | Alta | 65% | Alta | Medio | **1** |
| Bar/caffetterie | Alto | Molto alta | 70% | Alta | Medio | **2** |
| Parrucchieri/estetica | Alto | Media | 80% | Alta | Basso* | **3** |
| Palestre/corsi | Alto | Ricorrente | 85% | Media | Basso* | **4** |
| Artigiani/servizi casa | Medio | Bassa | 50% | Media | Basso | **5** |
| Officine/gommisti | Medio | Bassa | 45% | Media | Medio | **6** |
| Professionisti (commercialista, ecc.) | Medio | Ricorrente | 90% | Media | Basso* | **7** |
| Gastronomie/alimentari di qualità | Medio | Alta | 35% | Bassa | **Alto** | 8 (25–50% max) |
| Abbigliamento locale | Medio | Bassa | 55% | Media | Medio | 9 |
| E-commerce locale | Medio | Media | var. | Alta (piano Ecommerce [FATTO]) | Medio | 10 |
| Da evitare in fase 1 | Farmacie (vincoli), carburanti (margine 2%), GDO, elettronica (margine 8–12%) | | | | | — |

\* basso accumulo perché alto margine = alto assorbimento: possono accettare 100% e "produrre" il servizio con costo vivo minimo. Le categorie a margine basso vanno incluse più avanti **come luoghi di rispesa** (è lì che i merchant vorranno spendere i Ky) ma con accettazione parziale 25–50%.

---

# 13. MODELLO DI RICAVO DI KOSMOPAY

Valutazione sintetica dei 20 modelli del brief (chi paga → potenziale → compatibilità col bonus):

**Pilastri (fare subito):** (2) abbonamento mensile/annuale attività per piano — già strutturato nei 4 piani [FATTO], è il ricavo più prevedibile e "alla Sardex"; (4) commissione 3–4% sull'incassato Ky (pagata dal merchant, percepita come costo marketing); (18) onboarding/setup a pagamento post-pilota (99–199 € una tantum, waivable in promozione).
**Acceleratori (6–12 mesi):** (9–10) visibilità e campagne sponsorizzate nella directory (già ordinata per piano [FATTO] — monetizzarla); (12) reportistica avanzata merchant (base già esistente [FATTO: `MerchantReportController`]); (11) CRM/loyalty; (20) fee sui piani rateali (motore già presente).
**Strategici (12+ mesi):** (15–16) **white label per consorzi, CCN, Comuni, franchising** — il branding è già configurabile [FATTO: `SystemSetting::branding()`], il prodotto è multi-circuito in potenza: è il ricavo B2B2B a più alto margine e la vera scalabilità; (13) API a pagamento oltre soglie; (14) plugin e-commerce.
**Da evitare/rimandare:** (3) commissione sulle ricariche (tassa il gesto che vogliamo incentivare); (5) commissione sul bonus (incomprensibile); (19) consulenza generica (non scala).
**Modello consigliato:** freemium B2B — Anagrafica gratis (solo directory), Biglietto ~19 €/mese, Vetrina ~39 €/mese, Ecommerce ~79 €/mese [IPOTESI di listino da validare nel pilota] + fee 3% + premium. Ricavo medio per attività a regime: 450–700 €/anno.

---

# 14. CONCORRENTI (ricerca del 17/07/2026)

| Concorrente | Modello | Target | Bonus | Monetizzazione | Vantaggi | Limiti | Differenza da KosmoPay |
|---|---|---|---|---|---|---|---|
| **SardexPay** (e circuiti regionali gemelli: Felix Campania, ecc.) | Credito commerciale B2B, crediti non convertibili, fido reciproco | Imprese | No bonus consumer | Canone annuale + % transato | Rete matura, brand, 15+ anni | B2C marginale; niente incentivo consumer | KosmoPay = stesso impianto B2B **+ leva B2C 25% + MLM + tech più moderna** ([fonte](https://www.sardexpay.net/knowledge-base-web/about-kb-add-ons/), [analisi](https://www.innogestcapital.com/sardex-come-funziona-un-circuito-di-credito-complementare/), [Felix](https://www.circuitofelix.net/il-circuito/)) |
| App cashback nazionali (PAYBACK, cashback su acquisti online, carte crypto tipo Bybit) | Cashback % su acquisti | Consumer | 1–10% (fino a 30% promo) | Affiliazione/interchange | Zero attrito, brand nazionali | Nessun radicamento locale, % basse | KosmoPay offre 25% ma **vincolato al territorio** — incomparabilmente più alto, spendibilità più stretta ([panorama app](https://www.cashxflow.com/cashback-app-italia-migliori/), [PAYBACK](https://www.payback.it/app/app-mobile)) |
| Piattaforme fedeltà per CCN/negozi (CityShops, ShoppingPlus, MaxMarketing cashback commercianti) | Carta fedeltà/cashback di rete locale, spesso per centri commerciali naturali | Merchant/consorzi | Cashback punti | SaaS ai merchant/enti | Semplici, economiche | Non sono moneta: niente B2B, niente fido/rate/netting | KosmoPay ha il **layer monetario e bancario di circuito** che queste non hanno ([CityShops](https://cityshopscard.it/), [ShoppingPlus](https://www.shoppingplus.it/centri-commerciali-naturali-circuiti-negozi/), [MaxMarketing](https://www.maxmarketing.it/cashback-commercianti/)) |
| Gift card / buoni (Amazon locale, buoni spesa comunali) | Voucher prepagati | Consumer/PA | Sconti occasionali | Commissioni emissione | Semplicità normativa | Monouso, niente rete | I buoni spesa comunali sono un **canale white label** più che un concorrente |
| BNPL (Scalapay, Klarna) | Rateizzazione | Consumer | No | Fee merchant 4–6% | Adozione alta | Niente rete locale | KosmoPay ha già rate interne senza interessi [FATTO] |
| Satispay | Wallet pagamenti + cashback promo | Consumer/merchant | Promo variabili | Fee micro | Rete enorme, brand | Cashback modesto, nessuna logica di circuito | Concorrente sul gesto di pagamento, non sul modello di rete |

**Elementi distintivi difendibili:** combinazione unica B2B (fido/netting/rate) + B2C (bonus) + white label; densità locale come barriera; contratto e motore già pronti. **Facilmente copiabili:** il claim 25% (chiunque può promettere di più bruciando cassa); la directory. **Barriera reale:** la reciprocità di spesa B2B della rete — chi la costruisce per primo in una zona vince quella zona.

---

# 15. POSIZIONAMENTO E COMUNICAZIONE

**Posizionamento scelto:** *"Il circuito di quartiere che aumenta il tuo potere d'acquisto"* — rete commerciale locale + sistema di vantaggi. **Evitare** in comunicazione pubblica: "moneta", "valuta", "conto corrente", "deposito", "investimento", "guadagno" (rischio percezione finanziaria — §8 normativa). Usare: "circuito", "credito di circuito", "saldo KMoney", "ricarica".

- **UVP:** "Ricarichi 100 €, spendi 125 Ky nelle attività della tua zona."
- **Headline:** *Il tuo quartiere ti dà il 25% in più.*
- **Subheadline:** Ricarica in euro, ricevi KMoney con il 25% di bonus e spendili in bar, ristoranti, negozi e servizi aderenti vicino a te.
- **Payoff:** *KosmoPay. Vale di più, vicino a te.*
- **Descrizione breve:** KosmoPay è il circuito delle attività locali: ricarichi in euro, ottieni KMoney con bonus e li spendi nella rete. Le attività trovano clienti nuovi e rispendono i KMoney tra loro.
- **3 vantaggi cliente:** +25% subito · spendi dove vivi · paghi col telefono in 2 secondi.
- **3 vantaggi attività:** clienti nuovi senza anticipo · decidi tu quanto accettare · rispendi l'incasso nella rete.
- **3 vantaggi territorio:** il valore resta nel quartiere · le attività si comprano a vicenda · meno dipendenza dalle grandi piattaforme.
- **CTA primaria:** "Ricarica e ricevi il 25% in più" · **CTA secondaria:** "Porta la tua attività nel circuito".
- **Condizioni da mostrare sempre accanto al claim:** *I KMoney si spendono solo nelle attività aderenti, ciascuna indica la percentuale del prezzo pagabile in KMoney; non sono convertibili in euro; [eventuale scadenza del bonus]. Elenco attività e condizioni complete sul portale.*

---

# 16. PROPOSTA COMMERCIALE PER LE ATTIVITÀ

**Presentazione 2 minuti:** già in §2.4.
**Script telefonico (30''):** "Buongiorno, sono [nome] di KosmoPay, il circuito delle attività di [zona]. Stiamo selezionando un solo [categoria] per il quartiere: i clienti del circuito ricaricano euro, ricevono il 25% in più in KMoney e possono spenderli solo nelle attività aderenti — quindi la cercano apposta. Per lei zero costi per 90 giorni, decide lei quanta parte del prezzo accettare. Le rubo 15 minuti in negozio questa settimana: martedì o giovedì?"
**Messaggio WhatsApp:** "Ciao [nome], sono [nome] di KosmoPay 👋 Nel quartiere sta partendo il circuito KMoney: i clienti ricaricano e hanno il 25% in più da spendere SOLO nelle attività aderenti. Cerchiamo un/una [categoria] per [zona] — prova gratuita 90 giorni, decidi tu quanto accettare. Ti va se passo 15 minuti per fartelo vedere? Qui i dettagli: kosmopay.it"
**Email commerciale (oggetto: "Un solo [categoria] nel circuito di [zona] — ti interessa?"):** struttura problema→meccanismo→prova gratuita→esclusiva→CTA appuntamento, 120 parole, un solo link.
**Brochure (testo base):** "Clienti nuovi. Zero anticipo. / I clienti KosmoPay hanno KMoney da spendere e li spendono qui. / Decidi tu quanto accettare: 25, 50, 75 o 100%. / Rispendi l'incasso nella rete: fornitori, servizi, colleghi. / 90 giorni di prova, esci quando vuoi."
**Demo (scaletta 15'):** 1. mappa/directory col suo posto già mockato (2') · 2. pagamento QR dal vivo con conto demo (3') · 3. dashboard incassi e % accettazione (3') · 4. dove rispendere: fornitori aderenti (3') · 5. condizioni pilota e firma onboarding (4').
**Proposta pilota:** 90 giorni, canone 0, fee 0, esclusiva di categoria, 50 Ky demo, report a 30/60/90 giorni, disdetta libera; al termine, listino standard sottoscritto ora (parte solo se resta).
**Offerta founding member:** primi 30: −50% canone primo anno + badge "Fondatore" in directory.

**Le 15 obiezioni e le risposte (sintesi):**
1. *"Perché dovrei fare il 25% di sconto?"* — Non lo fa lei: il bonus lo emette il circuito. Lei incassa il prezzo pieno in KMoney; il suo costo reale è la parte di incasso che riceve in Ky, che però rispende nella rete. E lo paga solo su clienti che sono entrati davvero.
2. *"Chi paga il bonus?"* — Il circuito, con gli euro delle ricariche: servono a far girare la rete (acquisti del circuito presso gli aderenti) e a coprirne i costi. [Risposta onesta resa possibile SOLO se si adotta il modello α di §27.]
3. *"Cosa faccio con i Ky?"* — Paga fornitori e servizi aderenti; le mostriamo subito le 10 attività dove può spenderli — se per la sua categoria mancano, le portiamo noi (è il nostro lavoro).
4. *"Posso convertirli in euro?"* — No, ed è il motivo per cui la rete funziona: il valore resta dentro e torna anche da lei.
5. *"E se non trovo dove spenderli?"* — Può abbassare la % di accettazione in ogni momento, anche al 25%; e il tetto massimo di saldo la protegge dall'accumulo.
6. *"Quanto mi costa?"* — Pilota: zero. Poi canone da [X] €/mese e 3% sull'incassato in Ky: meno di qualunque campagna che le porti clienti *già paganti*.
7. *"Come aumento il fatturato?"* — Clienti nuovi + clienti che tornano (hanno saldo da spendere) + vendita delle ore/tavoli vuoti.
8. *"Come non perdo margine?"* — Accettazione parziale sui prodotti a basso margine, 100% su servizi ad alto margine e capacità invenduta.
9. *"Posso scegliere quanti Ky accettare?"* — Sì: 0/25/50/75/100%, anche per prodotto, modificabile sempre. [FATTO]
10. *"Se esco dalla rete?"* — Disdetta secondo contratto; i Ky restano spendibili come credito di fornitura (entro 1 anno). [FATTO: Art. 13]
11. *"È legale?"* — Circuito di credito commerciale con contratto di adesione, KYC e antiriciclaggio; modello analogo a circuiti attivi da 15 anni in Italia. [Da rafforzare dopo il parere legale.]
12. *"I miei dati?"* — GDPR, nessuna cessione a terzi. [FATTO: FAQ]
13. *"Devo cambiare cassa/POS?"* — No: QR stampato o telefono. Integrazione API/e-commerce se la vuole. [FATTO]
14. *"E se il circuito chiude?"* — Trasparenza mensile su transato e Ky in circolazione (report pubblico di rete) + i suoi Ky restano crediti verso gli aderenti.
15. *"Non ho tempo."* — Onboarding 30 minuti in negozio, lo facciamo noi.

---

# 17. PROPOSTA PER I CLIENTI

**Testo homepage / Come funziona / FAQ:** vedi §19 (testi pronti).
**Messaggio promozionale (SMS/push):** "Nel tuo quartiere ricarichi 100 € e spendi 125 Ky: pizzeria, parrucchiere, palestra e altre 30 attività ti aspettano. Scopri dove → [link]"
**Post social (lancio):** "📍[Quartiere], da oggi il tuo quartiere ti dà il 25% in più. Ricarichi 100 €, ricevi 125 KMoney, li spendi in 30+ attività qui intorno. Trova le attività sulla mappa e ricarica in 2 minuti. #KosmoPay #[quartiere]"
**Video script (30''):** [Inquadratura: bancone del bar] "Questo caffè te lo paga il tuo quartiere." [Cut: app, ricarica 100→125] "Ricarichi 100, ricevi 125." [Cut: QR alla cassa, 3 attività diverse] "Li spendi qui, qui e qui." [Chiusura: mappa con pin] "KosmoPay. Vale di più, vicino a te."
**Esempio di utilizzo settimanale:** lun colazione 3 Ky · mer pranzo 12 Ky · ven parrucchiere 35 Ky · sab pizza in due 40 Ky · dom colazione famiglia 15 Ky → 105 Ky/settimana possibili con la sola rete MVN di §9.
**Referral:** "Invita un amico: 10 Ky a te e 10 a lui alla sua prima ricarica" (motore referral già presente [FATTO]; accredito bonus da implementare come CashbackRule dedicata).

---

# 18. ANALISI UX/UI DEL SITO E DEL PRODOTTO

*Analisi basata sul contenuto pubblico della homepage e sulla struttura delle view nel repo; niente test invasivi.*

**Problemi principali rilevati (formato tabella problemi):**

| ID | Area | Problema | Evidenza | Impatto | Gravità | Soluzione | Sforzo |
|---|---|---|---|---|---|---|---|
| U1 | Homepage | Il vantaggio consumer (25%) **non esiste sul sito**: posizionamento solo B2B "moneta complementare del Gruppo Kosmos" | Fetch 17/07 [FATTO] | Il funnel B2C non può partire | Critico* | Nuova homepage (§19) | M |
| U2 | Fiducia | "5+ aziende, 21+ transazioni" comunica rete vuota | homepage | Diffidenza sia clienti sia merchant | Alto | Rimuovere contatori finché < 30/1.000; sostituire con categorie e mappa di zona | XS |
| U3 | Chiarezza | "Moneta complementare trasversale" è gergo; nessun esempio numerico | homepage | Comprensione lenta | Alto | Esempio "100→125" above the fold (§19) | S |
| U4 | Funnel cliente | Ricarica raggiungibile solo dopo registrazione+KYC+2FA+contratto; nessuna preview delle attività senza account | struttura route [FATTO: middleware stack] | Abbandono altissimo per il B2C | Alto | Directory e mappa pubbliche; KYC leggero per KYP sotto soglia | M |
| U5 | Ricerca attività | Directory senza mappa né geolocalizzazione | route/view [FATTO: nessuna route mappa] | Il claim "vicino a te" non è dimostrabile | Alto | Mappa con raggio e filtro categoria (§20) | M |
| U6 | Coerenza brand | KMoney vs KosmoPay vs KNM vs Kosmos usati in modo intercambiabile (sito, contratto, footer) | F15 | Percezione di improvvisazione, rischio fiducia | Medio | Naming system unico (KosmoPay = piattaforma; KMoney/Ky = credito) | S |
| U7 | Mobile | Portale Blade responsive ma non PWA; pagamento al banco richiede troppi tap | viste portal | Frizione al momento chiave | Medio | PWA + shortcut "Paga" (push già presenti [FATTO]) | M |
| U8 | Percorso merchant | Nessuna pagina pubblica "per le attività" con listino e calcolatore di ritorno | sito | Il commerciale non ha landing di appoggio | Alto | Pagina dedicata (§19/§23) | S |

\* critico rispetto all'obiettivo B2C del brief; il sito attuale è coerente con l'attuale posizionamento B2B.

**Simulazione degli 8 percorsi (sintesi):** i percorsi 1–3 (scoperta, ricarica, dove spendere) oggi falliscono per U1/U4/U5. I percorsi 4–6 (attività) funzionano nel portale [FATTO: kit merchant, report, % accettazione] ma manca la landing pubblica (U8). Il percorso 7 (partner rete locale) non ha alcuna pagina. Il percorso 8 (sviluppatore) è il migliore: API docs + OpenAPI pubblica [FATTO: `DocsController`, `/api/openapi.json`].

---

# 19. NUOVA HOMEPAGE (testi pronti)

1. **Hero** — Titolo: "Il tuo quartiere ti dà il 25% in più." Sotto: "Ricarica in euro, ricevi KMoney con il 25% di bonus e spendili nelle attività aderenti vicino a te." Visual: mappa di quartiere con pin reali. CTA1 "Ricarica e ricevi il 25%" · CTA2 "Hai un'attività? Entra nel circuito". Prova di fiducia: loghi delle prime attività + "Rete di [quartiere]".
2. **Il vantaggio in numeri** — riquadro interattivo: slider ricarica → "100 € → **125 Ky**". Micro-copy con condizioni (§15).
3. **Come funziona (cliente)** — 3 step: Ricarichi (carta/PayPal/bonifico) → Ricevi il bonus → Paghi col QR nelle attività aderenti. CTA "Apri il conto gratis".
4. **Dove spendere** — mappa/elenco per categoria con badge "%Kmoney accettata" [FATTO: dato già esistente]. Obiettivo: dimostrare la densità. CTA "Vedi tutte le attività".
5. **Esempio reale** — la settimana di Giulia (§17), con scontrini illustrati.
6. **Per le attività** — "Clienti nuovi, zero anticipo": 3 bullet (§15) + mini-calcolatore "quanti clienti in più vale il tuo quartiere" + CTA "Richiedi una demo di 15 minuti".
7. **Sicurezza e trasparenza** — KYC, 2FA/passkey, estratto conto, report mensile di rete (transato, attività attive). Obiettivo: fiducia. *(niente claim bancari)*
8. **FAQ** — le 6 attuali [FATTO] + "Chi paga il bonus?", "I KMoney scadono?", "Posso convertirli in euro?" con risposte oneste da §6/§16.
9. **Doppio invito finale** — split: "Sono un cliente → ricarica" / "Ho un'attività → parliamone".
10. **Footer** — dati societari **coerenti** (sanare F15), legal completi, contatti, social.

---

# 20. NUOVE FUNZIONALITÀ (prioritizzate)

**P0 — necessarie per il pilota B2C** *(alto valore, sforzo S–M)*
1. **Scadenza/vesting del bonus** — problema: passività perpetua; funzionamento: i Ky bonus hanno `expires_at` (nuovo campo su una tabella `bonus_lots` o riuso CashbackRule); metrica: % bonus spesi entro 6 mesi. Sforzo M, rischio basso.
2. **Mappa attività + ricerca per categoria pubblica** — sblocca U4/U5; metrica: sessioni mappa→ricarica. Sforzo M.
3. **Pagamento misto Ky/€ dichiarato in ricevuta** — oggi la parte € è fuori piattaforma; almeno registrarla nel movimento (campo memo strutturato). Sforzo S.
4. **Limiti campagna per giorno/categoria** (Modello D) — estensione di CashbackRule/Listing. Sforzo M.
5. **Report pubblico di rete** (transato mensile, n. attività, Ky in circolazione) — fiducia merchant; i dati esistono già [FATTO: EmissionController li calcola]. Sforzo S.

**P1 — crescita (3–6 mesi):** referral con premio automatico in Ky; promozioni/coupon merchant self-service; notifica "saldo in scadenza"; PWA con paga-rapido; preferiti; pagina partner/white label; plugin WooCommerce (il piano Ecommerce esiste già).
**P2 — regime:** CRM merchant, campagne sponsorizzate in directory (monetizzazione), compensazioni multilaterali assistite (il netting bilaterale esiste [FATTO]), marketplace B2B fornitori, integrazione POS, portali di zona white label.
**Da NON fare ora:** app native (PWA basta), Magento/Shopify (nessuna domanda), NFC card su larga scala (costo hardware; il motore c'è già [FATTO]).

---

# 21. SICUREZZA DEL SISTEMA

*Dall'analisi del codice (non test invasivi). Il livello complessivo è **sorprendentemente alto per lo stadio del progetto**: partita doppia con `balance_after` per entry, idempotency key ovunque, lock pessimistici ordinati, audit log, reconciliation (`VerifyAccountingIntegrity` + `ReconcileBalances` + `CheckSystemAccountContention`), 2FA/passkey/step-up/PIN, CSP middleware, sotto-conti con limiti.*

| ID | Area | Problema | Evidenza | Impatto | Gravità | Soluzione | Sforzo |
|---|---|---|---|---|---|---|---|
| S1 | Autorizzazioni | Nessuna Laravel Policy: authz sparsa nei controller (`abort_unless(...canAccessBackoffice())`) | assenza `*Policy*` nel repo [FATTO] | Rischio IDOR su nuove rotte future | Alto | Introdurre Policy su Account/Transfer/Company; test di autorizzazione | M |
| S2 | Config prod | `APP_DEBUG` in produzione mai verificato esplicitamente | memoria progetto 16/07 | Leak di stack trace | Alto | Verifica .env prod + Sentry già presente | XS |
| S3 | Ricariche | Conferma bonifico manuale admin senza doppia approvazione (four-eyes) | `adminConfirmBankTransfer` [FATTO] | Frode interna / errore su emissione KY | Medio | Secondo approvatore sopra soglia; già c'è AuditLog | S |
| S4 | Concorrenza | Lock del conto sistema serializza fee/cashback/topup (contention nota, commentata nel codice) | `bookFee()` docblock [FATTO] | Latenza a scala, non correttezza | Basso | Coda dedicata per fee (già ipotizzata nel commento) | M |
| S5 | Webhook Stripe | Corsa webhook/success gestita con idempotency ✓; PayPal capture senza verifica firma webhook (flusso redirect only) | `paypalCapture` | Basso (server-to-server capture) | Basso | Aggiungere verifica IPN/webhook PayPal | S |
| S6 | CSP | `unsafe-inline` (deprioritizzato su scelta esplicita) | memoria | XSS amplificato | Medio | Nonce-based CSP quando possibile | M |
| S7 | Controller monolite | `PortalController` ~88 KB: superficie d'errore e review difficile | dimensione file [FATTO] | Manutenzione/regressioni | Medio | Split incrementale (non riscrittura) | M |
| S8 | MLM payout | Liquidazioni EUR manuali: IBAN in tabella dedicata ✓, ma processo payout da blindare (step-up, doppia firma) | `MlmPayoutService` | Frode/errore su denaro reale | Alto | Step-up + approvazione a 4 occhi + export tracciato | S |

Requisiti del brief (atomicità, id univoci, no duplicazione, tracciabilità, rollback, idempotenza, saldi negativi controllati, riconciliabilità): **tutti già soddisfatti dal motore** [FATTO], con l'unica eccezione della governance organizzativa (S3/S8: le protezioni sono tecniche, non procedurali).

---

# 22. ARCHITETTURA TECNICA

**Giudizio: non riscrivere nulla.** Il progetto ha già: double-entry ledger ✓, transaction journal (Transfer+LedgerEntry) ✓, idempotency key ✓, DB transaction + lockForUpdate ✓, audit trail ✓, storni come transazioni inverse ✓, reconciliation job ✓, UUID ✓, API versionata (v1) ✓, queue jobs ✓, 670 test ✓.

Interventi evolutivi consigliati (in ordine): (1) split di `PortalController` (1.900 righe) in controller per dominio — meccanico, riduce rischio regressioni; (2) Policy + Form Request sistematiche; (3) coda asincrona per fee/cashback per togliere contention dal conto sistema (S4); (4) balance snapshot mensile per chiusure veloci e audit; (5) tabella `bonus_lots` con scadenza per il vesting del bonus (P0.1); (6) rate limiting esplicito sulle rotte di pagamento; (7) ambiente di staging separato dal prod (la memoria di progetto segnala confusione test/prod sullo stesso hosting — da sanare prima del pilota).

---

# 23. SEO E CONTENT MARKETING

Stato: sito one-page, nessun contenuto indicizzabile per intenti di ricerca; brand "KosmoPay" senza volume. Strategia: **local-first**, non keyword nazionali.
Pagine da creare (intento → keyword → CTA): "Come funziona KosmoPay" (informazionale, "circuito kmoney come funziona" → ricarica) · "Ricarichi 100 € e ricevi 125 Ky" (transazionale brand → ricarica) · "KosmoPay per i negozi/ristoranti/parrucchieri di [città]" (commerciale locale, una per categoria+città → demo) · "Attività aderenti a [quartiere]" (locale, generata dalla directory con schema.org LocalBusiness [dati già nel DB]) · "Sicurezza e trasparenza" · "Prezzi per le attività" · "API e integrazioni" (già forte [FATTO]) · FAQ con schema FAQPage · casi di successo post-pilota. Tecnica: sitemap, meta per pagina, structured data, Core Web Vitals (Vite già ok). Le pagine directory pubbliche sono il moltiplicatore SEO: ogni attività aderente = una pagina locale indicizzabile che porta clienti a lei e al circuito.

---

# 24. METRICHE DA MONITORARE

Le 6 vitali (North Star in grassetto), poi il pannello completo:

| Metrica | Formula | Fonte evento | Frequenza | Obiettivo pilota |
|---|---|---|---|---|
| **Tasso di rispesa merchant** | Ky spesi da conti business / Ky incassati da conti business (rolling 90gg) | LedgerEntry | Settimanale | ≥ 50% |
| Redemption clienti | Ky spesi / Ky emessi (per coorte di ricarica) | Transfer kind=kycard_topup vs pagamenti | Mensile | ≥ 60% a 60gg |
| Concentrazione Ky | quota Ky detenuti da top-3 attività | Account.available_balance | Settimanale | < 40% |
| Attivazione | ricaricanti / registrati | KyCardPurchase completed | Settimanale | ≥ 40% |
| Frequenza | transazioni/cliente attivo/mese | Transfer | Mensile | ≥ 3 |
| Ricavo per attività | (fee+canone) / attività attiva | contabilità | Mensile | ≥ 35 €/mese post-pilota |

Pannello completo (definizione sintetica): attività contattate/aderenti/attive (CRM commerciale); CAC attività = costi commerciali/adesioni; clienti registrati→verificati→ricaricanti (funnel); ricarica media; tempo alla prima transazione (target < 7gg); Ky emessi/spesi/dormienti; velocità di circolazione = transato/Ky in circolazione (target > 1×/trimestre); incremento fatturato per attività (survey+dati); retention M1/M3 cliente (≥ 60%/40%); churn merchant (< 5%/trim); transazioni fallite; rimborsi; frodi; saldo medio; NPS clienti (≥ 40) e merchant (≥ 30). Gli eventi esistono già quasi tutti nel ledger [FATTO]; serve solo una dashboard admin che li aggreghi (l'`EmissionController` ne calcola già metà [FATTO]).

---

# 25. ROADMAP

| Attività | Obiettivo | Impatto | Sforzo | Costo | Rischio | Dipendenze | Priorità |
|---|---|---:|---:|---:|---|---|---|
| **Prime 2 settimane** |
| Parere legale (qualificazione KMoney + L.173/2005 + claim 25%) | Sbloccare/riprogettare | Alto | S | 3–6 k€ | — | — | Indispensabile |
| Decidere il modello economico (questo report, §27) | Regole del pilota | Alto | S | 0 | — | — | Indispensabile |
| Sanare incoerenza societaria (F15) + separare staging/prod | Fiducia/base legale | Alto | S | 0–1 k€ | Basso | Legale | Indispensabile |
| Configurare card pilota (25% 1ª ricarica, 10% dopo) | Bonus governato | Alto | XS | 0 | Basso | Modello | Quick win |
| Estrarre dati prod (card, fee, canoni attivi → chiudere M1–M4) | Baseline | Medio | XS | 0 | — | — | Quick win |
| **Primi 30 giorni** |
| Scadenza bonus + report rete + mappa pubblica (P0) | Prodotto pilota-ready | Alto | M | dev interno | Medio | — | Indispensabile |
| Nuova homepage + landing attività (§19) | Funnel | Alto | S–M | 0–2 k€ | Basso | Posizionamento | Indispensabile |
| Reclutamento 30 attività (Fase 2 §10) | MVN | Alto | L | 5 k€ | Medio | Materiali | Indispensabile |
| **60 giorni** — lancio clienti in zona, referral attivo, eventi; monitor metriche §24 settimanale | | | | | | | |
| **90 giorni** — valutazione pilota (criteri §10 Fase 5), decisione scala/pivot | | | | | | | |
| **6 mesi** — consolidamento: listino canoni attivo, fee 3%, premium visibilità, 60 attività, 1.000 ricaricanti; split PortalController + Policy (S1/S7) | | | | | | | |
| **12 mesi** — seconda e terza zona (playbook replicabile), white label per primo consorzio/CCN, valutazione app PWA→store | | | | | | | |
| **Da evitare** — scalare il MLM sui depositi prima del parere legale; contatori "5+ aziende" in homepage; bonus 25% perpetuo su ogni ricarica; app native ora | | | | | | | |

---

# 26. BACKLOG TECNICO (primi 8 task)

| # | Titolo | File coinvolti | Attuale → Previsto | Criteri di accettazione | Rischio | Priorità |
|---|---|---|---|---|---|---|
| T1 | Scadenza bonus ricarica | nuova migration `bonus_lots`, `KyCardController::creditKy()`, job scadenza | bonus perpetuo → bonus con `expires_at` (12 mesi), spesa FIFO bonus-first | test: bonus scaduto non spendibile; ledger invariato; storno lotti | Medio (tocca il motore) | P0 |
| T2 | Mappa/directory pubblica | route pubblica, `PortalController::buildCompanyDirectoryData` estratto in service, view | directory solo autenticata → pubblica con geolocalizzazione (lat/lng su Company) | pagina indicizzabile, schema.org, filtro categoria/raggio | Basso | P0 |
| T3 | Report pubblico di rete | riuso metriche `EmissionController` in pagina pubblica cacheata | dati solo admin → trasparenza mensile | numeri = admin; cache 24h | Basso | P0 |
| T4 | Campagne bonus per giorno/categoria | `CashbackRule` (campi day_of_week/sector), admin UI | regole solo per target → anche temporali/categoria | test regole combinate | Basso | P1 |
| T5 | Policy + test authz | nuove Policy, controller | abort_unless sparsi → Policy centralizzate | test 403 su risorse altrui per ogni rotta portale | Medio | P1 |
| T6 | Split PortalController | `PortalController` → 5-6 controller | 1.900 righe → < 400/file | 670+ test verdi invariati | Medio | P1 |
| T7 | Four-eyes su bonifici > soglia e payout MLM | `KyCardController`, `MlmPayoutService` | conferma singola → doppia approvazione sopra 500 € | audit di entrambe le firme | Basso | P1 |
| T8 | Fee/cashback via coda | `TransferBookingService::bookFee` | afterCommit sincrono → job dedicato | nessuna fee persa (idempotency), contention ridotta | Medio | P2 |

---

# 27. RISULTATI FINALI

## 27.1 Il modello consigliato

- **Chi versa gli euro:** il cliente, alla società gestore (una sola entità giuridica, da sanare F15), su conto dedicato alle ricariche, contabilizzato come **debito verso il circuito**, non come ricavo.
- **Chi emette i Ky:** la Cassa Circuito, come oggi [FATTO], con tetto di emissione legato al transato (§9).
- **Chi sostiene il bonus:** ripartito per costruzione — 25% solo sulla prima ricarica (budget acquisizione del gestore), 10% a regime; le attività contribuiscono con fee 3% e canoni; il gestore si impegna a **re-immettere nel circuito α ≥ 35% degli euro incassati** (acquisti di beni/servizi presso gli aderenti per i propri costi operativi, premi, forniture) finché il tasso di rispesa merchant < 60%.
- **Come guadagna KosmoPay:** canoni per piano (freemium→79 €/mese), fee 3% sull'incassato Ky, servizi premium/visibilità, white label; il breakage (Ky prescritti) è riserva, non profitto da pianificare.
- **Come guadagna l'attività:** margine sui clienti incrementali + rispesa B2B; **come usa i Ky:** fornitori aderenti, altre attività, canoni KosmoPay pagabili in Ky (da introdurre: è un riassorbitore perfetto).
- **Limiti necessari:** % accettazione per attività [FATTO], max_balance per tutte [FATTO, da configurare], tetto per movimento [FATTO], scadenza bonus 12 mesi (T1), bonus pieno solo 1ª ricarica.
- **Come evitare squilibri:** monitor concentrazione settimanale (§24), broker che "sblocca" i merchant accumulatori portando loro fornitori, netting attivo [FATTO], stop temporaneo all'emissione bonus se rispesa < 40%.

## 27.2 Esempio economico completo (100 clienti, 20 attività, un mese)

[IPOTESI: ricarica media 100 €, tutte prime ricariche col 25%, redemption 70% nel mese, fee 3%, canoni non ancora attivi]

```
Ricariche:        100 × 100 € = 10.000 € incassati dal gestore
Ky emessi:        12.500 (10.000 base + 2.500 bonus)
Ky spesi nel mese: 8.750  → fatturato lordo alle 20 attività (437 Ky medi cad.)
Fee 3%:              262 Ky → Cassa (ricavo KosmoPay in Ky)
Rispesa B2B:       le attività rispendono il 50% (4.375 Ky) tra loro e verso fornitori
Riassorbimento α=35%: il gestore spende 3.500 € in acquisti presso gli aderenti
                   → le attività convertono 3.500 Ky-equivalenti in vendite "vere"
Risultato mese:
  Cliente:   +25% potere d'acquisto (2.500 € di valore extra ricevuto in rete)
  Attività:  +8.750 di fatturato Ky, di cui ~3.500 riassorbiti subito, ~4.375 rispesi,
             ~875 in giacenza (10% — sotto soglia di allarme)
  KosmoPay:  +10.000 € cash − 3.500 € riassorbiti = +6.500 € cassa,
             +262 Ky fee, passività residua ~3.750 Ky
  Circuito:  Ky in circolazione = 12.500 − 262 (fee alla Cassa) − eventuali riassorbimenti
```

Il mese si chiude in equilibrio **percepito** (nessun merchant accumula oltre soglia) e con cassa positiva; la passività residua è il "carburante" dei mesi successivi, governata da scadenza e α.

## 27.3 Le 10 condizioni necessarie

1. Parere legale positivo (o adeguamento) su qualificazione KMoney per il B2C.
2. Ridisegno o sospensione del programma KNM finché non è validato ex L. 173/2005 (compensi ancorati al transato, non ai depositi reclutati).
3. Bonus 25% limitato alla prima ricarica (o a campagne a budget); regime ≤ 10%.
4. Scadenza del bonus (12 mesi) e tetti di emissione.
5. MVN raggiunto **prima** del lancio pubblico clienti (≥30 attività, categorie coperte).
6. Impegno α ≥ 35% di riassorbimento finché rispesa merchant < 60%.
7. Fee 3% + listino canoni sottoscritto (anche se scontato/azzerato nel pilota).
8. Un'unica entità giuridica e contrattuale coerente (F15) + staging separato dal prod.
9. Dashboard metriche §24 attiva dal giorno 1 del pilota.
10. Una persona commerciale dedicata alla rete (il prodotto non si vende da solo).

## 27.4 Le 10 azioni da fare subito (in ordine)

1. Incaricare il legale (qualificazione + MLM + claim). 2. Decidere il modello §27.1 e metterlo per iscritto (regolamento circuito). 3. Sanare F15 e i testi contrattuali. 4. Configurare le card pilota (25% 1ª / 10%) — zero sviluppo. 5. Estrarre i dati prod mancanti (M1–M4). 6. Sviluppare T1–T3 (scadenza bonus, mappa, report rete). 7. Nuova homepage + landing attività. 8. Scegliere la zona pilota e la lista delle 100 attività da contattare. 9. Preparare kit commerciale (§16) e listino post-pilota. 10. Attivare la dashboard metriche.

## 27.5 Piano dei prossimi 90 giorni (settimanale)

Sett. 1–2: azioni 1–5 · Sett. 3–4: sviluppo T1–T3, homepage, kit; prime 20 visite commerciali · Sett. 5–6: altre 40 visite, onboarding primi 10 merchant, formazione · Sett. 7–8: completare 30 merchant, mappa online, soft-launch amici&famiglie (50 clienti) · Sett. 9: lancio pubblico di zona (eventi, vetrine, social) · Sett. 10–12: acquisizione clienti, promo giorni deboli, review settimanale metriche · Sett. 13: valutazione (criteri §10.5), decisione: scala / correggi / pivot B2B-only.

## 27.6 Valutazione finale (1–10)

| Dimensione | Voto | Nota |
|---|---:|---|
| Utilità | 8 | Problema vero per merchant locali; per il cliente dipende dalla densità |
| Attrattiva cliente | 9 | Il 25% è il claim più forte del mercato — se spendibile |
| Attrattiva attività | 7 | Ottima per alto margine; da spiegare bene la rispesa |
| Fattibilità tecnica | 9 | Piattaforma già pronta oltre le necessità del pilota |
| Fattibilità economica | 5 | Sostenibile solo col modello §27.1; 25% flat perpetuo insostenibile |
| Scalabilità | 7 | Playbook per zona replicabile; white label come moltiplicatore |
| Sicurezza | 8 | Motore finanziario solido; gap organizzativi (S1–S3, S8) |
| Chiarezza | 6 | Oggi confusa (B2B vs B2C, naming); risolvibile con §15/§19 |
| Potenziale commerciale | 7 | Spazio di posizionamento reale tra Sardex e le app cashback |

## 27.7 Decisione

**PROCEDERE CON UN TEST PILOTA**, che incorpora "modificare il modello prima del lancio": il pilota va eseguito **solo** con le condizioni 1–10 di §27.3 (in particolare: parere legale, bonus 25% limitato alla prima ricarica, scadenza bonus, MVN raggiunto, riassorbimento α). Motivazione: (a) l'asset tecnico è pronto e collaudato — non sfruttarlo sarebbe uno spreco; (b) il claim 25% è commercialmente potentissimo ma economicamente sostenibile solo come costo di acquisizione governato, non come promessa perpetua; (c) le due incognite decisive — comportamento di rispesa dei merchant e qualificazione legale — non si risolvono a tavolino: una si misura con 90 giorni di pilota, l'altra con un incarico legale da 3–6 k€. Se il pilota restituisce rispesa ≥ 50%, redemption ≥ 60% e churn merchant < 20%, il progetto merita il GO e il piano di §11; se no, il fallback naturale è il posizionamento B2B puro (Sardex-like) che il contratto e la piattaforma già supportano oggi.

---

*Report redatto il 17/07/2026. Fonti web citate nel testo: SardexPay, Innogest, Circuito Felix, Banca d'Italia (IMEL), Diritto Bancario, dirittodellinformatica.it, CityShops, ShoppingPlus, MaxMarketing, PAYBACK, cashxflow.com. Analisi del codice sui file citati; nessun dato di produzione è stato letto o modificato.*
