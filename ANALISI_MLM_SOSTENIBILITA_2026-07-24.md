# Analisi di sostenibilità del piano Multilevel (MLM) KNM — kmoney-app

Data: 24/07/2026 — analisi su richiesta di Laura, letto MLM_PROPOSAL.md (v1.0, 2026-07-01) e il codice attuale (`MlmRankEngine`, `MlmCommissionEngine`, `MlmBonusService`, `MlmAwardService`, `MlmPointsService`, migration `mlm_rank_requirements`/`mlm_point_rules`/`ky_cards`, stato al 24/07/2026 — include già il requisito "clienti minimi" aggiunto il 22/07).

**Non sono un commercialista né un avvocato**: le parti fiscali/legali di questo documento sono un inquadramento dei rischi da verificare con un professionista abilitato prima del lancio pubblico, non un parere vincolante. Il §5 lo ripete perché è il punto più importante di tutto il documento.

---

## 0. Verdetto in una riga

**È sostenibile, ma non ancora — e per un motivo preciso, quantificabile, che conferma esattamente il tuo istinto**: oggi il sistema può generare bonus e promozioni pagabili in euro reali **senza una sola ricarica**, perché i punti (la valuta interna che sblocca tutto: qualifiche, BasiQ, bonus) si ottengono anche dalla sola *registrazione* di un cliente — evento gratuito, istantaneo, e verificato **prima ancora dell'esito KYC**. Finché resta così, il piano non è finanziariamente ancorato al fatturato reale: è ancorato al numero di iscrizioni. È esattamente la definizione tecnica di un rischio di vendita piramidale, non solo un problema di conti.

La correzione che hai già in mente — punti come mix registrazione/ricarica, più un minimo di clienti che *pagano* oltre al minimo che *si iscrivono* — è la fix corretta e sufficiente sul piano finanziario. Al §4 trovi i numeri concreti da impostare.

---

## 1. Come funziona oggi (riassunto tecnico)

- **Punti**: ogni agente matura punti dai propri clienti diretti. Registrazione = 1 punto/90gg (editabile, oggi attivo di default). Ricarica = punti letti dalla **KY Card** acquistata (`ky_cards.mlm_points`/`mlm_points_duration_days`): oggi 2 punti per qualunque card ≥120€, con durata crescente (30gg / 180gg / 360gg secondo il taglio). Sotto i 120€ una ricarica **non genera alcun punto**.
- **BasiQ**: un agente nuovo diventa BasiQ se somma 12 punti *attivi* entro 30 giorni dall'attivazione. È l'evento che fa scattare la cascata bonus settimanale sulla upline.
- **Qualifiche** (Basic→Manager): ciascuna richiede punti attivi totali (12/24/48/48/48/48) **+ da poco (22/07) un minimo di clienti registrati diretti** (6/12/24/24/24/24) **+** requisiti strutturali (Basic al 1° livello, colonne con Key/Senior/Top, colonne da 300 punti). Punti e "clienti registrati" **non distinguono se il cliente ha mai versato un euro**.
- **Commissioni** (mensili, 1° del mese): calcolate non sull'importo pieno della ricarica ma su **"Prov K"** = importo × margine KNM (default 30%, configurabile 1–100%). Diretta fino al 40% di Prov K (in base ai punti dell'agente); indiretta 4/2/1/0,5/8% sui livelli 1–5 della sua downline (15,5% totale), poi 0,5% per livello oltre il 5° per gli agenti già Top/SuperVisor/Manager, con uno stop legato al primo pari-grado incontrato nel ramo.
- **Bonus di struttura** (ogni mercoledì, telescopici): Key 60€, Senior 110€, Top 150€, SuperVisor 180€, Manager 200€ — la somma dei payout in una catena è **sempre pari all'importo del grado più alto presente**, quindi il costo per singolo evento BasiQ è **strutturalmente limitato a 200€ al massimo**, qualunque sia la profondità della rete. Buona notizia, vedi §3.1.
- **Bonus Diretti KNM** (una tantum, per soglie di punti attivi nella vita dell'agente): 4pt→200€, 6pt→300€, 12pt→400€, cumulabili fino a 900€.
- **Extra Bonus** (una tantum, alla prima promozione di grado): Senior 300€, Top 3.000€, SuperVisor 5.000€, Manager 20.000€.
- Tutto questo è **EUR reale**, fuori dal circuito KY, liquidato con bonifico esterno gestito dall'admin (§10 della proposta) — quindi è un vero esborso di cassa, non un movimento contabile interno.

---

## 2. Il problema quantificato: quanto costa un BasiQ "gratis"

Ho tracciato il percorso più economico possibile per un operatore che voglia far scattare bonus senza generare fatturato, usando esattamente le regole di cui sopra:

1. Un nuovo utente si registra come agente (gratis, istantaneo).
2. Nei successivi 30 giorni, invita **12 clienti** che si limitano ad **aprire un conto** — nessuna ricarica. `AuthController` chiama `MlmPointsService::awardRegistrationPoints()` **al momento della registrazione**, con `kyc_status = 'pending'`: il punto matura **prima** che il KYC sia mai stato verificato.
3. 12 registrazioni × 1 punto = 12 punti attivi entro 30 giorni → **l'agente diventa BasiQ**. Costo per l'azienda finora: **0€** di margine generato, ma:
   - **900€** di Bonus Diretti KNM al nuovo agente stesso (ha toccato le tre soglie 4/6/12 punti nello stesso colpo);
   - fino a **200€** di cascata settimanale alla sua upline, se esiste già un ramo con un Manager sopra di lui (0€ se la upline è ancora tutta "start", ma il costo cresce automaticamente man mano che la rete matura — è un costo strutturale, non un caso limite).
4. Ripetibile **ogni 30 giorni**, per ogni nuova identità "agente" creata, senza alcun limite tecnico nel codice attuale.

**Costo per singolo evento fabbricato: fino a 1.100€, ricavo generato: 0€.** Non è un edge case teorico: è il percorso più *breve* nel sistema attuale, più corto di qualunque percorso che passi da una vera ricarica. Un sistema di incentivi, per costruzione, viene sempre trovato e seguito lungo il percorso più economico — motivo in più per chiuderlo prima del lancio pubblico, non dopo.

Alle qualifiche più alte (Top in su) c'è già una difesa naturale non trascurabile: il requisito "3 colonne da 300 punti" per Top richiede, se fatto solo con registrazioni, **centinaia di clienti realmente registrati per colonna** — un argine operativo enorme anche senza ricariche. È Basic/Key/BasiQ il livello dove oggi non c'è **nessun** argine, ed è anche il livello che si ripete più spesso (BasiQ è settimanale, non una tantum).

---

## 3. Sostenibilità nello scenario "normale" (con ricariche vere)

Qui la matematica è già solida, a patto che il margine KNM resti positivo — verifichiamolo.

### 3.1 Il tetto per singolo evento è già limitato dove conta di più

I bonus a importo fisso (struttura, diretti, extra) sono **numeri assoluti**, non percentuali sul fatturato — quindi il loro costo totale dipende dal *numero di eventi* (promozioni, BasiQ), non dal volume di ricariche. Finché quegli eventi restano ancorati a fatturato reale (§4), il loro costo cresce nella stessa proporzione del fatturato che li ha generati, ed è quindi sostenibile per costruzione. Il problema del §2 è esattamente che oggi *non* sono ancorati.

### 3.2 Le commissioni percentuali (dirette+indirette): margine sempre positivo nei casi realistici

Le commissioni si calcolano su Prov K (30% dell'importo di default), non sull'importo pieno — quindi il tetto assoluto per singola ricarica è già una frazione del deposito, non il deposito intero. Esempio con una ricarica da 1.200€ (margine 30% → Prov K 360€):

| Scenario | % di Prov K pagata | Commissioni (€) | Margine KNM residuo (€) | Margine residuo su ricarica |
|---|---|---|---|---|
| Agente diretto a 20pt, 1 solo livello indiretto attivo | ~24% | 86,40€ | 273,60€ | 22,8% |
| Agente diretto max (40%) + 5 livelli indiretti pieni (15,5%) | 55,5% | 199,80€ | 160,20€ | 13,4% |
| Come sopra + 10 livelli extra oltre il 5° (Top/SuperVisor/Manager, 0,5% l'uno) | 60,5% | 217,80€ | 142,20€ | 11,9% |

In tutti gli scenari realistici il margine residuo resta positivo, anche nel caso "tutta la catena è ai massimi". Il punto di rottura teorico (commissioni ≥ 100% di Prov K, cioè KNM perde sulla singola riga) richiede circa **90 livelli aggiuntivi** di agenti Top/SuperVisor/Manager impilati sopra un singolo cliente oltre al quinto livello — organizzativamente implausibile con la rete che state costruendo oggi, ma **non impossibile per costruzione del motore** man mano che la rete matura e si approfondisce. Consiglio comunque un tetto di sicurezza a prescindere (vedi §4.4): costa pochissimo da implementare ora, mentre costerebbe react a un incidente reale.

### 3.3 Il vero vincolo non è la percentuale, è la cassa

Le commissioni/bonus sono liquidati in EUR reali con bonifico esterno (non è un giroconto interno KY). Questo significa che oltre al rapporto percentuale serve una vera **policy di tesoreria**: il margine (Prov K) matura mensilmente sulle ricariche del mese precedente, ma un Extra Bonus da 20.000€ (prima promozione a Manager) o una raffica di bonus settimanali può generare un picco di cassa che il margine di un solo mese non copre, anche se il piano è sostenibile "in media". Raccomando:
- un fondo di riserva minimo dedicato ai payout MLM, alimentato da una quota fissa del margine Prov K incassato ogni mese (non lo 100%: tenerne indietro una parte, es. 20-30%, come cuscinetto);
- una soglia di approvazione admin già prevista (§6 della proposta, "richiesta IBAN → approvazione") che va usata anche come **freno manuale** sui payout singoli sopra una certa soglia (es. Extra Bonus SuperVisor/Manager), non solo come formalità.

---

## 4. Le correzioni raccomandate — con i numeri

Ecco la proposta concreta per il "mix registrazioni/ricariche" che avevi in mente, con valori già coerenti con la struttura esistente (`mlm_rank_requirements`, card da 120€+ = 2 punti).

### 4.1 Nuovo campo: punti minimi *da ricarica* (`min_deposit_points`), accanto a `min_points`

Stessa tabella di oggi, ma richiedendo che **almeno metà** dei punti del grado provenga da eventi di deposito (non registrazione). Con le card attuali (2 punti/ricarica ≥120€), significa in pratica "almeno N/2 ricariche vere da ≥120€":

| Grado | min_points (oggi) | **min_deposit_points (nuovo)** | Ricariche reali equivalenti |
|---|---|---|---|
| Basic | 12 | **6** | ≥ 3 ricariche da 120€+ |
| Key | 24 | **12** | ≥ 6 ricariche da 120€+ |
| Senior | 48 | **24** | ≥ 12 ricariche da 120€+ |
| Top | 48 | **24** | ≥ 12 ricariche da 120€+ |
| SuperVisor | 48 | **24** | ≥ 12 ricariche da 120€+ |
| Manager | 48 | **24** | ≥ 12 ricariche da 120€+ |

### 4.2 Nuovo campo: clienti minimi *paganti* (`min_paying_clients`), accanto a `min_clients`

Stesso principio applicato al requisito "clienti registrati" aggiunto il 22/07 — richiedere che una quota di quei clienti abbia fatto **almeno una ricarica**, non solo aperto il conto:

| Grado | min_clients (oggi) | **min_paying_clients (nuovo)** |
|---|---|---|
| Basic | 6 | **2** |
| Key | 12 | **4** |
| Senior | 24 | **8** |
| Top / SuperVisor / Manager | 24 | **8** (o 10, vedi nota) |

Nota: per Top/SuperVisor/Manager puoi anche alzarlo a 10 senza rischi — a quel livello la rete è già abbastanza grande da assorbirlo, e alza ulteriormente l'argine contro il caso del §2.

### 4.3 Il punto più urgente: gate su BasiQ

BasiQ è l'evento più pericoloso perché è **ripetibile ogni 30 giorni** (non una tantum come le promozioni) e da solo vale fino a 1.100€ per occorrenza (§2). Applica la stessa regola 50/50: **almeno 6 dei 12 punti che fanno scattare BasiQ devono provenire da ricariche**, cioè almeno 3 ricariche reali da ≥120€ nella stessa finestra di 30 giorni. Con questa unica modifica, il percorso del §2 smette di esistere: non puoi più fabbricare un BasiQ, né il Bonus Diretto KNM da 900€ che lo accompagna, senza far transitare almeno ~360€ di ricariche vere (3×120€) — che generano almeno ~108€ di Prov K reale a copertura parziale del costo.

### 4.4 Rete di sicurezza indipendente dal mix: tetto sulle commissioni

A prescindere dal punto precedente, aggiungi un tetto assoluto in `MlmCommissionEngine`: la somma di diretta+indiretta su una singola riga di Prov K non supera mai, es., l'**80%** di quella riga. È una riga di codice (un `min()` finale) che garantisce che KNM non vada mai sotto sulla singola commissione, indipendentemente da quanto profonda o qualificata diventi la rete in futuro (§3.2).

### 4.5 Rafforzativo economico, non solo strutturale: KYC prima dei punti

Oggi `awardRegistrationPoints()` scatta a `kyc_status = 'pending'`, cioè prima di qualsiasi verifica identità. Anche con il fix del §4.1–4.3 (che toglie l'incentivo economico), vale la pena spostare l'assegnazione punti al **KYC approvato**: alza il costo operativo di generare identità false, che oggi è sostanzialmente zero. Non sostituisce le soglie sui punti da ricarica — le affianca.

---

## 5. Il rischio legale (da NON sottovalutare, e da NON risolvere da solo)

Il documento MLM_PROPOSAL.md lo segnala già al punto 7.7, e lo confermo con più urgenza dopo aver visto il percorso del §2: un piano che eroga **bonus in denaro legati al solo reclutamento/registrazione di clienti**, indipendentemente da un acquisto reale, rientra nell'ambito di attenzione della normativa italiana sulla vendita piramidale (L. 173/2005, art. 5) — che vieta esplicitamente schemi in cui il guadagno di chi entra nella rete deriva prevalentemente dal reclutamento di nuovi partecipanti piuttosto che dalla vendita di beni/servizi reali.

Il fix del §4 è necessario ma da solo **non basta come garanzia legale**: riduce il rischio finanziario e rende il piano più difendibile (i bonus derivano ora, in parte misurabile, da transazioni reali), ma la qualificazione legale definitiva — "è o non è vendita piramidale ai sensi della norma" — dipende da dettagli che vanno oltre questo documento (natura del "prodotto" KY, se il margine KNM è remunerazione di un servizio reale, come viene comunicato il piano ai partecipanti, ecc.). **Raccomando una verifica formale con un legale specializzato in diritto commerciale/vendite dirette prima di qualunque lancio pubblico**, indipendentemente da quanto i numeri tornino qui.

Un secondo punto, di natura fiscale, da verificare col vostro commercialista: le commissioni EUR pagate agli agenti (bonifico esterno, fuori dal circuito KY) potrebbero far scattare, a seconda di come è strutturato il rapporto con gli agenti, obblighi di ritenuta d'acconto, fatturazione (se l'agente ha partita IVA) o iscrizione Enasarco (se il rapporto è qualificabile come agenzia commerciale). Non è detto che si applichi — dipende da come viene formalizzato il rapporto KNM↔agente — ma è un costo/adempimento che oggi non vedo modellato da nessuna parte nel codice o nella proposta, e vale la pena chiarirlo prima che il primo payout reale parta.

---

## 6. Riepilogo azioni consigliate, in ordine di urgenza

1. **Gate su BasiQ** (§4.3) — il fix a più alto impatto/costo più basso, chiude il percorso a costo zero del §2.
2. **`min_deposit_points` e `min_paying_clients`** sulle qualifiche (§4.1–4.2) — coerente con quello che avevi già in mente, valori proposti pronti da editare in `/admin/mlm-impostazioni` una volta aggiunti i campi.
3. **Parere legale L.173/2005** (§5) — in parallelo, non in sequenza: non bloccare l'uno per l'altro.
4. **Tetto di sicurezza sulle commissioni** (§4.4) — economico da fare ora, costoso da rimediare dopo.
5. **KYC prima dei punti** (§4.5) — rafforzativo, priorità più bassa dei primi due.
6. **Policy di riserva di cassa** (§3.3) — da avere pronta prima che il primo Extra Bonus da 20.000€ diventi reale, non dopo.

Se vuoi, il prossimo passo naturale è che io implementi i punti 1, 2 e 4 (sono modifiche di schema + motore contenute, nello stile di quanto già fatto per `min_clients` il 22/07) e ti prepari gli scenari di test in `mlm:simula` per verificare i numeri prima di toccare `/admin/mlm-impostazioni` in produzione — fammi sapere se procedo.
