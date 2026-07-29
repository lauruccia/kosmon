# Analisi 4 requisiti agenti/clienti/MLM — 27/07/2026

Solo analisi dello stato attuale del codice (kmoney-app), nessuna modifica applicata. Riferimenti a file e righe per verifica diretta.

---

## 1. "Agente non è cliente" + doppio conto (agente di se stesso)

**Requisito:** un agente non può essere anche cliente sullo stesso conto, ma può avere 2 conti — uno da agente e uno da cliente — con 2 email diverse e lo stesso numero di telefono. Ogni agente può registrarsi come cliente di se stesso.

**Stato attuale:**

- `mlm_role` è un singolo campo enum (`cliente`/`agente`) su `users` (`2026_07_02_090000_add_mlm_fields_to_users_table.php`): un utente è o cliente o agente, mai entrambi sulla stessa riga — quindi "agente non è cliente" è già strutturalmente garantito **per singolo account**.
- La registrazione (`AuthController::register()`) richiede email univoca (`unique:users,email`) ma **il telefono non ha alcun vincolo di unicità** (`'phone' => ['nullable','string','max:30']`, nessuna regola `unique`). Due account con lo stesso telefono sono già tecnicamente possibili oggi.
- Il link referral personale (`User::referralUrl()`, `referral_code`) risolve l'agente nel modo giusto: se un agente si registra un secondo account usando il **proprio** link referral, `AuthController::register()` risolve `$referrer` = se stesso, e `MlmTreeService::resolveAgentForNewClient()` (righe 86-97) ritorna l'agente stesso perché `$referrer->isMlmAgent()` è vero → il nuovo account cliente viene automaticamente agganciato con `mlm_client_agent_id` = l'agente stesso.
- **Quindi il meccanismo di base "agente diventa agente di riferimento di un secondo conto cliente proprio" esiste già**, ma solo come effetto collaterale del sistema di referral generico — non c'è un percorso dedicato/etichettato per farlo.

**Gap rispetto al requisito:**

- Nessuna UX dedicata: un agente non ha un bottone/percorso esplicito "Registrati come mio cliente" — deve sapere di usare il proprio link referral (`/portale/invita`), il che non è intuitivo e nessuno glielo suggerisce.
- Nessun controllo che impedisca scenari ambigui: es. il secondo conto "cliente di se stesso" potrebbe a sua volta presentare una richiesta di adesione al programma agenti (`mlm_agent_request_status`), creando un secondo agente collegato alla stessa persona senza nessi espliciti.
- Nessun campo che colleghi esplicitamente i due account come "stessa persona" (oggi il legame è implicito solo tramite `mlm_client_agent_id = id proprio` + eventualmente stesso telefono, non c'è un flag tipo `self_client_of_agent_id` o verifica automatica "stesso telefono coerente").
- Nessuna verifica anti-abuso: oggi qualsiasi utente (non solo un agente) può registrare più conti con lo stesso telefono e email diverse, visto che il telefono non è mai stato pensato come identificatore univoco.

**Proposta (senza codice, solo linee guida):**

1. Aggiungere nel portale agente un'azione esplicita "Registrati come mio cliente" che pre-compila il form di registrazione con il proprio `referral_code` e magari pre-riempie il telefono dell'agente (editabile).
2. Validare in `AuthController::register()` che se il referrer risolto è l'agente stesso E il telefono coincide con quello dell'agente, il nuovo account venga marcato esplicitamente (nuovo campo `is_agent_self_client` booleano o semplicemente si documenta che è implicito da `mlm_client_agent_id === referrer_id` + stesso telefono).
3. Decidere una regola esplicita: un "conto cliente di se stesso" può comunque fare richiesta per diventare agente in futuro? Se no, bloccarlo in `User::canRequestMlmAgent()`.
4. Nessuna migration è strettamente necessaria se si accetta il legame implicito attuale; se si vuole un legame esplicito e interrogabile, serve un campo tipo `linked_agent_self_user_id` su `users`.

---

## 2. Admin deve poter riassegnare i clienti ad altro agente

**Requisito:** l'admin deve poter cambiare l'agente di riferimento di un cliente già registrato.

**Stato attuale:**

- Esiste già **`MlmTreeService::moveAgent()`** (righe 416-442) + UI dedicata (`Admin/MlmController::moveForm()`/`move()`, viste `admin.mlm.move`) — ma sposta **un intero agente (e il suo sottoalbero)** sotto un nuovo sponsor nell'albero MLM (closure table `mlm_agent_closure`). Non tocca `mlm_client_agent_id` di nessun cliente.
- Il campo che lega un CLIENTE al suo agente (`User::mlm_client_agent_id`, colonna su `users`) **non viene mai scritto se non alla registrazione** (`AuthController::register()`, riga 109: `'mlm_client_agent_id' => $nearestAgentAncestor?->id`). Nessun controller successivo lo aggiorna.
- `Admin/UserController::updateUser()` (la scheda utente admin) gestisce nome/email/telefono/ruoli/limiti di conto, ma **non ha nessun campo per cambiare l'agente di riferimento** di un cliente.
- `Admin/MlmController::show()` (scheda agente) mostra i clienti attribuiti (`$user->mlmClients()`), in sola lettura — nessuna azione di riassegnazione da lì.

**Gap:** manca del tutto la funzionalità. Oggi un cliente resta agganciato per sempre allo stesso agente (o a nessuno, se registrato senza referral) salvo intervento diretto sul database.

**Proposta:**

1. Nuovo metodo di servizio, es. `MlmTreeService::reassignClient(User $client, ?User $newAgent, ?User $actor)`: verifica che `$client->isMlmClient()`, che `$newAgent` sia un agente attivo (o null per "scollega"), scrive `mlm_client_agent_id`, scrive `AuditLog` (evento tipo `mlm.client_reassigned`, con `old_agent_id`/`new_agent_id`), stessa filosofia di `moveAgent()` (operazione puramente strutturale, non tocca punti/commissioni storiche già generate).
2. UI: azione "Riassegna agente" nella scheda cliente admin (`admin/user-show.blade.php`) o nella lista clienti di un agente (`admin/mlm/show.blade.php`), con ricerca dell'agente di destinazione (stesso pattern di `admin.mlm.move`).
3. Punto aperto da decidere con Laura: la riassegnazione deve rivalutare retroattivamente i punti/commissioni già maturati dal vecchio agente su quel cliente? La convenzione esistente nel codice (vedi `moveAgent()` e la nota "operazione puramente strutturale") è **NO — resta storico**, solo le nuove ricariche/registrazioni da quel momento in poi vanno al nuovo agente. Consigliato restare coerenti con questo precedente.
4. Route/permesso: riusare `users.manage` o un permesso MLM dedicato già esistente (`backoffice.access` + verifica ruolo admin, vedi `AuthorizesBackoffice`).

---

## 3. Segnalazioni con guadagno KY differenziato (amico/agente/attività) ed entrata in struttura

**Requisito:** un cliente che segnala un amico (non entra in struttura) guadagna 10ky se l'amico si registra; se segnala un agente guadagna 50ky se l'agente si registra; se segnala un'attività guadagna 100ky se fa il contratto. L'agente segnalato entra a far parte della struttura dell'agente di riferimento del cliente segnalante (stesso per l'attività): il cliente guadagna, ma chi è stato segnalato entra nella "struttura" dell'agente del segnalante, non diventa un ramo autonomo del cliente.

**Stato attuale — questo è il punto con il gap più ampio:**

- Esiste già un sistema di referral **generico e strutturale**: `referral_code` + `referred_by_user_id` (migration `2026_06_10_100000_add_referral_fields_to_users_table.php`), pagina `/portale/invita` (`ReferralController`), e la risoluzione dell'agente per il nuovo cliente (`MlmTreeService::resolveAgentForNewClient()`).
- **Nessun bonus KY viene mai erogato per una segnalazione.** Ho verificato: `ReferralController` si limita a mostrare link e lista invitati, nessuna chiamata a `TransferBookingService`. `MlmPointsService::awardRegistrationPoints()` assegna **punti MLM all'agente** (per le sue qualifiche), non KY al cliente che segnala. Non esiste alcuna differenziazione "amico / agente / attività" nel flusso di registrazione attuale.
- **Il concetto stesso di "segnalare un agente" o "segnalare un'attività" (azienda) come referral non esiste ancora.** Oggi il referral_code è unico per persona e non distingue il tipo di destinatario: chi si registra semplicemente sceglie `account_holder_type` (private/company) e può opzionalmente spuntare "voglio diventare agente" (`become_agent`), ma questo è indipendente da chi lo ha invitato.
- Esiste però tutta l'infrastruttura riusabile per costruire questa feature:
  - **Erogazione KY**: pattern già collaudato in `KycController::maybeErogateWelcomeBonus()` (righe 299-355) — booking via `TransferBookingService::book()` dal conto di sistema, con `idempotency_key` per evitare doppie erogazioni, try/catch fire-and-forget per non bloccare il flusso principale. Stesso pattern riusabile per il bonus di segnalazione.
  - **Trigger "contratto completato"**: per un'attività, il momento naturale è l'approvazione KYC azienda (`KycController::approve()`, che porta `company.kyc_status` ad `approved` — è il punto in cui oggi scatta già il welcome bonus). Per un agente, il momento è la firma del contratto di nomina (`User::mlm_agent_contract_signed_at`, gestito da `MlmAgentContractController`).
  - **Struttura MLM**: `MlmTreeService::attachAgent(User $agent, ?User $sponsor)` (righe 32-77) già gestisce l'inserimento di un nuovo agente sotto uno sponsor nell'albero (closure table). Se il segnalatore è un cliente (non un agente), lo sponsor da passare a `attachAgent()` dovrebbe essere **l'agente di riferimento del cliente segnalante** (`$client->mlmClientAgent`), esattamente come richiesto ("l'agente deve essere segnalato all'agente di riferimento").

**Gap puntuali:**

1. Nessuna tabella/colonna traccia **il tipo di segnalazione** (amico/agente/attività) né l'importo dovuto — serve un nuovo modello, es. `ReferralReward` (o estensione di `MlmInvitation`), con: segnalatore, segnalato, tipo (`friend`/`agent`/`company`), importo KY dovuto, stato (`pending`/`paid`), riferimento all'evento che sblocca il pagamento (registrazione vs firma contratto).
2. Nessun importo configurabile da admin per i 3 casi (oggi tutto ciò che è "importo" MLM passa da `SystemSetting` o tabelle dedicate come `mlm_point_rules` — stesso pattern da riusare per i 3 importi KY, es. nuova tabella `referral_reward_rules` con colonne `type`, `amount_ky_cents`).
3. **Nessuna distinzione di "tipo di destinatario atteso" al momento in cui viene generato/condiviso il link/codice di segnalazione**: oggi un cliente ha un solo `referral_code` generico. Per sapere se un nuovo iscritto era "segnalato come amico" vs "segnalato come agente" vs "segnalato come attività" serve capire l'intento al momento dell'invito — le opzioni sono: (a) il segnalatore sceglie il tipo quando genera/condivide il link (3 varianti di link, o un parametro `?ref=CODE&tipo=agente`), oppure (b) si deduce automaticamente dal tipo di account che il segnalato sceglie in fase di registrazione (privato semplice = amico, spunta "become_agent" = agente, `account_holder_type=company` = attività) — quest'ultima opzione è più semplice da implementare e coerente con i campi già esistenti in `AuthController::register()`, ma non permette a un cliente di "segnalare specificamente un agente" nel senso di invitare qualcuno che lui SA diventerà agente (l'esito dipende dalla scelta di chi si registra, non da un'intenzione dichiarata a monte). Da chiarire con Laura quale dei due modelli rispecchia meglio il caso d'uso reale.
4. Il flusso "l'agente/attività segnalati entrano nella struttura dell'agente di riferimento del segnalante" richiede una modifica a `AuthController::register()`: oggi se chi si registra spunta `become_agent`, diventa poi agente (dopo approvazione admin + contratto) ma **non viene mai agganciato nell'albero MLM in quel momento** — la spunta "become_agent" oggi serve solo a creare una richiesta pending, e il vero inserimento nell'albero (`attachAgent`) va cercato: **verificare che `MlmAgentContractController` (firma contratto agente) chiami `attachAgent()` con lo sponsor giusto al momento dell'attivazione** — questo è il punto di aggancio da collegare al segnalatore originale (`referred_by_user_id`) risalendo al suo agente di riferimento.
5. Per l'attività (azienda): oggi non esiste alcun concetto di "l'azienda entra nella struttura di un agente" — le aziende non hanno `mlm_role`/`mlm_client_agent_id` (questi campi sono sulla tabella `users`, non su `companies`). Se un'attività deve "entrare in struttura" bisognerebbe chiarire cosa significa concretamente: il titolare dell'azienda (che è comunque uno `User` con `account_holder_type=company`) diventa cliente MLM dell'agente di riferimento del segnalante? Questo sembra già gestibile dal meccanismo esistente (il titolare che registra l'azienda è comunque un `User` con `mlm_client_agent_id`), ma va confermato che sia questa l'interpretazione voluta.

**Proposta di implementazione (alto livello, da confermare prima di scrivere codice):**

1. Nuova tabella `referral_reward_rules` (o estensione di `mlm_point_rules`, che ha già il pattern "regola per tipo, editabile da admin"): `type` (friend/agent/company), `amount_ky_cents`, `is_active`.
2. Nuova tabella `referral_rewards` (log dei bonus generati): `referrer_user_id`, `referred_user_id`, `type`, `amount_ky_cents`, `status` (`pending_contract`/`paid`), `paid_transfer_id`, timestamps — permette audit e idempotenza (stesso pattern del welcome bonus).
3. In `AuthController::register()`: quando risolto un `$referrer` valido, determinare il tipo di segnalazione (amico/agente/attività) secondo la regola scelta al punto 3 del gap sopra, e:
   - per "amico": creare subito la riga `referral_rewards` con stato `paid` ed erogare i 10ky (trigger = registrazione, già disponibile in quel momento).
   - per "agente"/"attività": creare la riga con stato `pending_contract`; il pagamento vero e proprio va agganciato al punto in cui il contratto viene firmato (`MlmAgentContractController` per l'agente, `KycController::approve()` per l'attività) — lì si cerca la riga `referral_rewards` pending per quell'utente e si eroga.
4. Nello stesso punto di attivazione (firma contratto agente), chiamare `MlmTreeService::attachAgent($newAgent, $sponsor)` dove `$sponsor` = `$referrer->isMlmClient() ? $referrer->mlmClientAgent : $referrer` (stessa logica già presente in `resolveAgentForNewClient()`), cosicché il nuovo agente entri nella struttura dell'agente di riferimento del cliente segnalante, non del cliente stesso (il cliente non è un agente e non può avere una downline).
5. Verificare con Laura: il bonus "amico" (10ky) vale per QUALSIASI amico segnalato che si registra come privato, o solo se non diventa mai agente/attività? Dal testo sembra: il tipo di bonus dipende dal tipo di account che il segnalato apre, quindi i 3 casi sono mutuamente esclusivi per costruzione (un utente non può essere contemporaneamente "amico generico" e "azienda").

---

## 4. Requisito "punti minimi da ricariche" nei livelli agente

**Requisito:** aggiungere ai requisiti di livello agente un minimo di punti da fare con le ricariche (non solo punti totali), configurabile da admin.

**Stato attuale:**

- Questo era già stato **analizzato in dettaglio il 24/07** (vedi `mlm_sostenibilita_ricariche_24_07.md` in memoria) — solo analisi, nessun codice scritto. La raccomandazione consegnata allora (punto 2 di quel report) era esattamente questo: nuovo campo `min_deposit_points` per grado.
- L'infrastruttura è pronta e già usata in un caso identico: `mlm_rank_requirements` (tabella + modello `MlmRankRequirement`) è **esattamente il pattern giusto** — la migration `2026_07_22_110000_add_min_clients_to_mlm_rank_requirements.php` mostra come è stato aggiunto un requisito analogo (`min_clients`) 5 giorni fa: nuova colonna + valori di seed per grado + uso in `MlmRankEngine`.
- `MlmRankEngine::evaluate()` (righe 110-203) calcola già le metriche da `MlmRankRequirement::METRIC_TO_REQUIREMENT_FIELD`, un array centrale che mappa metrica calcolata → colonna di requisito: aggiungere una nuova metrica richiede solo estendere quella mappa + calcolare il valore.
- Il ledger punti (`mlm_point_ledger`, modello `MlmPointLedgerEntry`) **distingue già la fonte del punto** con la colonna `source_type` (`registration` vs `deposit` — vedi `MlmPointsService::awardRegistrationPoints()` vs `awardDepositPoints()`), quindi calcolare "punti attivi SOLO da ricariche" è una query immediata: `mlmPointLedgerEntries()->where('source_type','deposit')->where(finestra validità)->sum('points')`.

**Gap:** manca solo la colonna di requisito + il calcolo della metrica + il collegamento nella mappa. Nessun ostacolo architetturale.

**Proposta (stesso pattern di `min_clients`, minima invasività):**

1. Migration: aggiungere `min_deposit_points` (unsigned int, default 0) a `mlm_rank_requirements`, con seed per grado — Laura dovrà indicare i valori (il report del 24/07 proponeva, come punto di partenza discutibile: Basic 6, Key 12, Senior/Top/SuperVisor/Manager 24, cioè 50% del totale richiesto — ma è una proposta, non un numero confermato da lei).
2. In `MlmRankEngine::evaluate()`: nuova metrica `deposit_points` = somma dei punti attivi del ledger dell'agente con `source_type = 'deposit'` (stessa finestra di validità già usata per `mlmActivePoints()`, che oggi somma TUTTI i source_type insieme).
3. Aggiungere `'deposit_points' => 'min_deposit_points'` a `METRIC_TO_REQUIREMENT_FIELD` e l'etichetta corrispondente in `RANK_LABELS` (es. "Punti da ricariche") — da quel momento la checklist "prossimo grado"/"mantenimento grado" e la retrocessione automatica lo includono gratis, senza altre modifiche.
4. UI admin: il form di `/admin/mlm-impostazioni` (`Admin/MlmSettingsController`) già elenca i campi di `mlm_rank_requirements` per grado — aggiungere il nuovo campo lì è un'estensione diretta dello stesso form.
5. Nota di coerenza con l'analisi del 24/07: questo requisito, da solo, **chiude già gran parte del bug economico** individuato allora (registrazioni gratuite che da sole facevano scattare BasiQ/bonus): se almeno metà dei punti di BasiQ deve venire da ricariche reali, il percorso "12 registrazioni gratis in 30gg" smette di bastare. Consigliato implementare questo requisito insieme al punto 1 di quel report (gate specifico su BasiQ), non solo genericamente su tutti i gradi.

---

## Riepilogo priorità/complessità

| # | Requisito | Complessità stimata | Terreno pronto? |
|---|---|---|---|
| 1 | Agente cliente di se stesso | Bassa (UX + piccoli controlli) | Sì, meccanismo di base già funzionante di fatto |
| 2 | Riassegnazione clienti da admin | Bassa/Media (1 metodo servizio + 1 vista) | Sì, pattern identico già esistente per gli agenti (`moveAgent`) |
| 3 | Segnalazioni con bonus KY differenziati + struttura | Alta (nuovo modello dati, nuova logica di trigger su 2 eventi diversi, decisioni di prodotto da chiarire) | Parziale — tutti i mattoncini esistono ma vanno assemblati ex novo |
| 4 | Requisito punti da ricariche nei livelli | Bassa (stesso pattern di `min_clients` di 5 giorni fa) | Sì, quasi meccanico |

Punti aperti da chiarire con Laura prima di passare all'implementazione: la modalità con cui un cliente "dichiara" che sta segnalando un amico/agente/attività (vedi punto 3, gap #3), i valori numerici dei nuovi requisiti punto 4, e se la riassegnazione cliente (punto 2) deve o meno rivalutare retroattivamente punti/commissioni già maturati.
