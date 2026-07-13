# Proposta: Sistema Multilevel Marketing (MLM) per kmoney-app

Versione 1.0 — 2026-07-01
Basata su: messaggio di sintesi, `Presentazione KNM.pptx`, `2°ParteKnm.pptx`, `ShortKSM_Network.pptx`, `mlm_piano.xlsx`

Questo documento è una proposta funzionale e tecnica da validare **prima** di scrivere codice. Contiene: il modello risolto (dove le fonti erano in conflitto), lo schema dati, la logica di calcolo, i job schedulati, l'integrazione con l'architettura esistente di kmoney-app, i punti ancora da confermare e un piano di implementazione a fasi.

---

## 1. Cosa ho trovato nei file

I 4 file caricati sono materiale di un progetto precedente chiamato **KNM (Kosmos Network Marketing)** — le 3 slide sono un deck commerciale/formativo, l'xlsx (`mlm_piano.xlsx`) sembra l'estrazione dati di un sistema realmente usato in passato (contiene utenti reali con nomi come `knm`, `francesco`, `lmtech`, `silpit`, colonne `kmoney`, stati `Agent`/`Customer`/`BasiQ`). Le tre fonti (tuo messaggio, slide, excel) **non sono sempre allineate tra loro** — ho risolto le discrepanze seguendo le decisioni che mi hai dato in chat (dettagliate al punto 2) e le ho verificate matematicamente dove possibile (punto 6.4).

Ho anche verificato la struttura attuale del progetto: esiste già un sistema di referral a un livello (`users.referred_by_user_id`, `referral_code`, `ReferralController`, pagina "Invita un amico" — aggiunto il 10/06/2026), ma **nessun albero multilivello, punti, qualifiche o commissioni**. Le "ricariche" KY avvengono tramite `KyCard` / `KyCardPurchase` (acquisto pacchetto EUR→KY, es. Stripe) — questo è il "deposito" a cui fa riferimento tutto il piano MLM.

---

## 2. Decisioni già prese (confermate da te)

| # | Decisione | Cosa significa in pratica |
|---|---|---|
| 1 | **Guadagni agente in EUR reali, fuori dal circuito KY** | Commissioni e bonus NON passano da `TransferBookingService` e NON toccano `available_balance` KY. Sono calcolati e accreditati in un ledger EUR separato, con liquidazione esterna (bonifico/altro) gestita dall'admin. |
| 2 | **Requisiti di qualifica: seguo le slide PowerPoint** | Uso i valori di `Presentazione KNM.pptx` / `2°ParteKnm.pptx` per i requisiti di avanzamento di grado (dettaglio al punto 4). |
| 3 | **Base commissioni: spalmata su 12 mesi** | Ogni deposito genera un "importo mensile" che resta commissionabile per N mesi consecutivi (come da glossario KNM), non solo nel mese del deposito. |

---

## 3. Attori e struttura dell'albero

- **Cliente**: si registra, deposita, non entra mai nell'albero multilivello. È sempre collegato all'agente (o all'utente) che l'ha invitato.
- **Agente**: si registra con "Codice KNM" (nel nostro caso: codice/link referral esistente), entra nell'albero, matura punti, sale di qualifica, guadagna commissioni e bonus.
- **Chiunque** (agente o cliente) può generare un link di invito. Riuso il campo esistente `users.referred_by_user_id` sia per "cliente → agente che l'ha invitato" sia per "agente → agente sponsor (upline)".
- **Colonna/ramo/branch** = il sotto-albero radicato in ciascun invitato diretto di 1° livello di un agente. È l'unità usata per i requisiti "N colonne diverse" e per i "punti colonna" (somma punti di tutto il sotto-albero).

**Punto aperto (vedi §7.1)**: cosa succede se un *cliente* (non agente) invita qualcuno — l'invitato risale al cliente (che non ha punti/commissioni, quindi l'evento si perde) o al primo agente antenato? Propongo la seconda opzione, ma va confermata.

---

## 4. Punti, qualifiche e requisiti (versione risolta dalle slide)

### 4.1 Punti Cliente (PC) — da registrazioni e depositi

Fonte: `mlm_piano.xlsx`, foglio *PC PUNTI CLIENTI* (coerente col tuo esempio). Il deposito minimo perché un utente conti come "cliente attivo" è **120 €**.

| Azione | Punti | Durata (mesi in cui restano attivi) | Punti totali generati |
|---|---|---|---|
| Apertura conto | 1 | 1 | 1 |
| Deposito 120 € | 1 | 1 | 1 |
| Deposito 1.200 € | 2/mese | 12 | 24 |
| Deposito 2.400 € | 2/mese | 24 | 48 |
| Deposito 3.600 € | 2/mese | 36 | 72 |

I punti oltre la soglia minima **si ripetono ogni mese** per la durata indicata (coerente con la decisione "smoothing 12 mesi" del §2.3) e poi scadono. "Punti attivi" di un agente = somma dei punti non ancora scaduti nel suo ledger personale.

### 4.2 Qualifiche agente

Ordine di grado, dal più basso al più alto: **Start → Basic → Key → Senior → Top → SuperVisor → Manager**.
(Le slide elencano graficamente "Top" prima di "Senior", ma l'ordine corretto è quello sopra: è l'unico coerente con gli importi bonus crescenti — 60 < 110 < 150 < 180 < 200 — verificati al §6.4. Lo confermo nel dettaglio al §7.2.)

**Aggiornato il 2026-07-13** (rilettura integrale delle 3 pptx, confermato da Laura): i requisiti seguono il testo LETTERALE delle slide — Senior = 3 Basic + 2 Key su 2 colonne, Top = 4 Basic + 3 colonne da 300 punti. La versione precedente di questa tabella li aveva scambiati insieme all'ordine dei nomi, ma le slide riepilogative ("Qualifiche", identiche nelle 3 presentazioni) sono internamente coerenti: il numero di Basic al 1° livello cresce in modo monotono col grado (2/3/4/5/6).

| Qualifica | Punti personali richiesti | Requisito aggiuntivo (dalla struttura sotto di me) |
|---|---|---|
| Start | 0 | Solo registrazione (codice/link invito) |
| Basic | 12 | — |
| Key | 24 | 2 Basic al 1° livello |
| Senior | 48 | 3 Basic al 1° livello **+** 2 Key su 2 colonne diverse |
| Top | 48 | 4 Basic al 1° livello **+** 3 colonne diverse con almeno 300 punti ciascuna |
| SuperVisor | 48 | 5 Basic al 1° livello **+** 2 Senior e 2 Top, su 4 colonne diverse |
| Manager | 48 | 6 Basic al 1° livello **+** 3 SuperVisor su 3 colonne diverse |

Regole di calcolo:
- "Colonne diverse" = i requisiti (Key, Senior/Top, SuperVisor) devono trovarsi in rami distinti radicati in invitati diversi di 1° livello — due agenti nello stesso ramo (uno sotto l'altro) non soddisfano il requisito.
- "3 colonne da 300 punti" = per almeno 3 rami di 1° livello, la somma dei punti attivi di tutto quel sotto-albero deve essere ≥ 300.
- Il ricalcolo qualifica è **automatico, continuo e BIDIREZIONALE** (deciso il 2026-07-13): i punti hanno una scadenza (finestra `valid_from`/`valid_until` nel ledger, come da "Tabella punti" e glossario delle slide) e quando scadono i requisiti possono venire meno. Il run notturno (`mlm:recalculate-points`) allinea il grado di ogni agente alla qualifica più alta soddisfatta in quel momento: promuove E retrocede, senza grado minimo garantito (si può tornare fino a Start) e senza periodo di grazia.
- La valutazione notturna procede **dal basso verso l'alto** (foglie → radice): così la retrocessione di un figlio (es. un Basic che scade a Start) si riflette sull'upline nella stessa esecuzione.
- La retrocessione **non ricalcola nulla retroattivamente**: bonus e commissioni già generati restano storici; il grado corrente vale solo per gli eventi futuri (cascata bonus, estensione oltre il 5° livello). Il flag **BasiQ resta storico** e non viene mai rimosso.

### 4.3 BasiQ

Un nuovo agente diventa **BasiQ** se raggiunge 12 punti personali entro 30 giorni dall'attivazione del codice. È lo stato che fa scattare i bonus di struttura (§6). Da notare: BasiQ ≠ qualifica "Basic" — è un flag temporale ("nuovo entrato che si è attivato in fretta").

**Punto aperto (§7.3)**: cosa succede a chi raggiunge 12 punti ma *dopo* i 30 giorni — diventa comunque "Basic" come qualifica (sì, i requisiti punti/colonna restano validi) ma **non genera mai bonus di struttura** per l'upline? Presumo di sì (il bonus è legato solo all'evento "diventare BasiQ", non alla qualifica Basic in sé) — da confermare.

---

## 5. Commissioni (in EUR, calcolate il 1° del mese alle 2:00)

Base di calcolo: **"importo mensile"** = deposito del cliente diviso per la durata di smoothing (§4.1, es. deposito 1.200€ → 100€/mese per 12 mesi), sommato su tutti i mesi ancora "attivi" per quel cliente. Non solo il deposito del mese corrente.

### 5.1 Commissioni dirette (sui propri clienti)

% applicata in base ai **punti attivi dell'agente** al momento del calcolo:

| Punti | % |
|---|---|
| fino a 5 | 0% |
| da 6 | 5% |
| da 12 | 10% |
| da 24 | 15% |
| da 48 | 20% |
| da 96 | 25% |
| da 150 | 30% |
| da 200 | 40% |

### 5.2 Commissioni indirette (sui clienti dei propri agenti in downline)

| Livello downline | % | Requisiti personali per percepirla (dal 2026-07-13) |
|---|---|---|
| 1 | 4% | 12 punti personali attivi |
| 2 | 2% | 12 punti personali attivi + 2 Basic al 1° livello |
| 3 | 1% | 24 punti personali attivi + 2 Basic al 1° livello |
| 4 | 0,5% | 24 punti personali attivi + 2 Basic al 1° livello |
| 5 | 8% | 48 punti personali attivi + 3 Basic al 1° livello |
| 6+ | 0,5% | solo agenti di grado Top/SuperVisor/Manager, con breakaway al primo Top/SuperVisor/Manager incontrato in ciascun ramo |

**Gating aggiunto il 2026-07-13** (confermato da Laura): la tabella "Criteri per i Compensi Indiretti" (2°ParteKnm slide 7) non dà solo le percentuali ma anche i requisiti personali minimi per incassare ciascun livello. I punti sono quelli attivi all'inizio del mese di calcolo; i Basic al 1° livello sono contati sul grado corrente dei figli diretti. Un agente che perde i requisiti (es. punti scaduti) smette di percepire i livelli corrispondenti dal mese successivo.

**Deciso il 2026-07-03** (vedi memoria `mlm_livello5_8percento_da_confermare`): il 5° livello ha un'aliquota propria dell'8%, uniforme per qualsiasi agente — non è "0,5% oltre il 4°" come implementato inizialmente. Lo 0,5% con breakaway (sezione "Compensi indiretti estesi" delle slide) si applica solo dal 6° livello in poi, e solo per agenti che hanno già raggiunto grado Top/SuperVisor/Manager. Confermato numericamente da tutte le tabelle "Esempio compensi" nelle 3 slide (es. Presentazione KNM slide 18: 18.432€ di V.A.P. al 5° livello × 8% = 1.475€, coerente con il "Guadagno mensile" totale mostrato).

---

## 6. Bonus di struttura (accreditati ogni mercoledì)

### 6.1 Importi per qualifica

| Qualifica | Bonus |
|---|---|
| Key | 60 € |
| Senior | 110 € |
| Top | 150 € |
| SuperVisor | 180 € |
| Manager | 200 € |

### 6.2 Meccanismo

Ogni volta che un agente in downline diventa **BasiQ**, si genera un evento bonus. Si individua la qualifica più alta presente nella catena upline (dal BasiQ fino alla radice, o fino al prossimo breakaway — stesso punto aperto del §5.2 sulla compressione). Il bonus totale generato = importo della qualifica più alta presente. Questo importo si distribuisce tra **tutte** le qualifiche (da Key in su) effettivamente presenti in quella catena, dal basso verso l'alto:

> `payout(qualifica) = importo_bonus(qualifica) − importo_bonus(qualifica presente immediatamente inferiore nella catena)`

Se non c'è nessuna qualifica inferiore presente, l'agente incassa l'intero importo della propria fascia.

### 6.3 Regola speciale Key

Il bonus Key (60 €) scatta solo **dal 3° Basic** acquisito in downline (i primi 2 Basic sono "consumati" per salire a Key stesso, coerente col requisito "2 Basic" per diventare Key).

### 6.4 Esempi verificati

Ho ricalcolato con uno script i tuoi esempi usando l'ordine Key(60) < Senior(110) < Top(150) < SuperVisor(180) < Manager(200):

| Catena presente (dal BasiQ in su) | Chi incassa | Come |
|---|---|---|
| Solo Key | Key: 60€ | 60 − 0 |
| Key, poi Top (Senior assente) | Key: 60€ · Top: 90€ | Top = 150 − 60 |
| Key, Senior, Top | Key: 60€ · Senior: 50€ · Top: 40€ | Senior = 110−60, Top = 150−110 |
| Key, Senior, Top, SuperVisor | Key: 60€ · Senior: 50€ · Top: 40€ · SuperVisor: 30€ | SuperVisor = 180−150 |

In ogni caso la somma dei payout = importo della qualifica più alta presente (torna sempre, verificato). **Nota**: i tuoi esempi in chat usano numeri identici (60/50/40) ma con le etichette Senior/Top scambiate rispetto alla tabella importi — è lo stesso tipo di inversione del §4.2. La meccanica che hai descritto è corretta al 100%; ho solo riallineato le etichette.

---

## 7. Punti ancora da confermare prima di scrivere codice

Questi non bloccano la stesura della proposta, ma **bloccano l'implementazione** se non confermati — sono scelte di business, non di programmazione.

1. **Cliente invitato da un cliente** (non da un agente): l'evento risale al primo agente antenato nell'albero, o non genera punti/commissioni per nessuno? Consiglio: risale al primo agente antenato.
2. **RISOLTO il 2026-07-13 — Ordine e requisiti Senior/Top**: l'ordine dei gradi resta Key<Senior<Top<SuperVisor<Manager (coerente con i bonus crescenti), ma i requisiti seguono il testo letterale delle slide: Senior = 48pt + 3 Basic + 2 Key su 2 colonne, Top = 48pt + 4 Basic + 3 colonne da 300 punti (la prima versione del §4.2 li aveva scambiati). Confermato da Laura dopo la rilettura integrale delle 3 pptx.
2-bis. **DECISO il 2026-07-13 — Retrocessione per scadenza punti**: il grado viene allineato ogni notte in entrambe le direzioni (vedi §4.2); nel motore commissioni è stato aggiunto il gating dei livelli indiretti 1-5 (vedi §5.2).
3. **BasiQ oltre i 30 giorni**: chi arriva a 12 punti dopo i 30 giorni diventa comunque "Basic" ma non genera mai bonus di struttura. Confermi?
4. **RISOLTO il 2026-07-03**: il 5° livello ha aliquota propria dell'8% (uniforme, non "0,5% oltre il 4°" come implementato inizialmente); dal 6° livello in poi 0,5% con compressione al prossimo Top/SuperVisor/Manager, solo per agenti già di grado Top/SuperVisor/Manager. Vedi §5.2.
5. **Requisito Manager**: le slide dicono "6 Basic + 3 SuperVisor su 3 colonne", ma sia l'excel che il tuo messaggio dicono "3 Senior" al posto di "3 SuperVisor". Ho seguito le slide come da tua indicazione, ma segnalo che 2 fonti su 3 dicono "Senior" — verifica.
6. **Liquidazione EUR**: come vengono pagati gli agenti? Bonifico manuale gestito da admin, soglia minima di payout, integrazione futura con uno strumento di pagamento (Stripe payout, PayPal)? Serve almeno un flusso "richiesta dati IBAN → approvazione admin → segnato come pagato" per la v1.
7. **Compliance MLM in Italia**: un piano che eroga punti/bonus **per la sola registrazione di un cliente** (non legato a un acquisto di prodotto/servizio) rientra nell'ambito di attenzione della normativa sulla vendita piramidale (L. 173/2005, art. 5) se il guadagno è prevalentemente legato al reclutamento piuttosto che alla vendita di beni/servizi reali. Non sono un consulente legale: raccomando una verifica con un legale specializzato prima del lancio pubblico, indipendentemente dall'implementazione tecnica.

---

## 8. Modello dati proposto

Estensione di `users` (o tabella satellite `mlm_agent_profiles` se preferisci non toccare la tabella principale):

```
users
  + mlm_role            enum('cliente','agente')  default 'cliente'
  + mlm_rank            enum('start','basic','key','senior','top','supervisor','manager') default 'start'
  + mlm_rank_updated_at timestamp nullable
  + mlm_basiq_at        timestamp nullable   -- quando ha raggiunto 12 punti entro 30gg
  + mlm_activated_at    timestamp nullable   -- data attivazione "codice" agente
```

Nuove tabelle:

| Tabella | Scopo |
|---|---|
| `mlm_point_ledger` | Log transazionale dei punti: `agent_user_id`, `source_type` (registration/deposit), `source_client_id`, `points`, `valid_from`, `valid_until`, `created_at`. I "punti attivi" = somma dove `valid_until >= oggi`. |
| `mlm_agent_closure` | Closure table dell'albero agenti (`ancestor_id`, `descendant_id`, `depth`, `branch_root_id`) per query aggregate veloci su colonne/rami senza ricorsione a runtime. |
| `mlm_rank_history` | Storico cambi qualifica, con snapshot dei requisiti verificati (audit/compliance). |
| `mlm_commission_runs` / `mlm_commissions` | Batch mensile e dettaglio commissioni (dirette/indirette) per agente, con `idempotency_key` per evitare doppi accrediti. |
| `mlm_bonus_events` / `mlm_bonus_payouts` | Evento BasiQ e distribuzione bonus per beneficiario, con settimana di riferimento (mercoledì). |
| `mlm_payouts` | Liquidazione EUR aggregata per agente/periodo: stato (pending/approved/paid), riferimento pagamento, note admin. |
| `mlm_payment_details` | Dati bancari/IBAN dell'agente per il payout — tabella separata per motivi di sicurezza/PII, non su `users`. |

Tutti gli importi in centesimi interi, coerente con la convenzione del progetto (`ky_format`/`ky_to_cents` per i KY; per l'EUR MLM userò un helper equivalente, es. `eur_format`/`eur_to_cents`, per non confondere le due valute).

---

## 9. Job schedulati (nuovi)

| Job | Frequenza | Compito |
|---|---|---|
| `RecalculateMlmPointsAndRanks` | giornaliero | Scadenza punti (fine durata smoothing), verifica BasiQ (12pt entro 30gg), avanzamento qualifica se requisiti soddisfatti |
| `CalculateMonthlyMlmCommissions` | 1° del mese, 02:00 | Commissioni dirette + indirette su base "importo mensile" attivo |
| `CalculateWeeklyMlmBonuses` | ogni mercoledì | Elabora eventi BasiQ della settimana, cascata bonus, crea `mlm_bonus_payouts` |

Tutti idempotenti (idempotency key su periodo+agente), con lock/transazione per evitare doppio calcolo in caso di re-run, e log in stile `AuditLog` per ogni accredito.

---

## 10. Perché EUR separato e non KY

La regola d'oro del progetto è "tutti i movimenti KY passano da `TransferBookingService`" — ma qui si tratta di guadagni in **euro reali**, non di movimenti nel circuito KY chiuso (che deve sempre sommare a zero). Un ledger MLM in EUR separato, con liquidazione esterna, evita di violare l'invarianza del circuito KY e mantiene la contabilità del circuito monetario indipendente dalla contabilità dei compensi di rete. Se in futuro vorrai permettere agli agenti di "convertire" il saldo EUR maturato in KY, sarà un'operazione esplicita e tracciata (un singolo `Transfer` di tipo dedicato, dal conto sistema), non un accredito automatico dei job MLM.

---

## 11. Piano di implementazione a fasi

1. **Fondamenta**: migrazioni schema (§8), estensione registrazione con `mlm_role`, generazione albero (closure table), pagina admin di sola lettura per ispezionare l'albero.
2. **Punti clienti**: ledger punti, eventi registrazione/deposito, job giornaliero di ricalcolo punti attivi.
3. **Qualifiche**: motore di valutazione requisiti (colonne, punti colonna, conteggio ranghi in downline), storico avanzamenti.
4. **Commissioni**: job mensile dirette + indirette, tabella `mlm_commissions`, vista agente "i miei guadagni".
5. **Bonus**: rilevazione BasiQ, job settimanale cascata bonus.
6. **Payout EUR**: raccolta IBAN, flusso approvazione admin, export contabile, stato pagamenti.
7. **Pannello admin MLM**: dashboard rete, override manuali (con audit log), reportistica.
8. **QA end-to-end**: test automatici sugli esempi numerici di questo documento (§6.4, §5) + test di carico sulla closure table con reti profonde.

---

## 12. Prossimo passo

Rispondimi sui 7 punti del §7 (anche solo "ok" dove sei d'accordo con la mia proposta di default) e comincio l'implementazione a partire dalla Fase 1.
