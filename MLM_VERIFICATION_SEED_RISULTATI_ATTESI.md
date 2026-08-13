# Seed di verifica MLM — risultati attesi

Questo file accompagna `MlmVerificationSeeder.php` (seeder Laravel) e
`MLM_VERIFICATION_SEED.sql` (stesso identico albero, come script SQL puro):
**stessi dati, due modi di caricarli.** Usa quello che preferisci.

Non sono numeri calcolati a mano: sono l'**output reale** di
`mlm:recalculate-points` → `mlm:calculate-weekly-bonuses` →
`mlm:calculate-commissions` lanciati contro questo identico albero in un
ambiente isolato (clone pulito, migrazioni da zero). Se li rilanci sui tuoi
dati e i numeri non coincidono, è un segnale reale da controllare, non un
errore di battitura mio.

## Come usarlo

**Opzione A — seeder Laravel** (consigliata, più comoda da ripetere):
1. Copia `MlmVerificationSeeder.php` in `database/seeders/`.
2. `php artisan db:seed --class=MlmVerificationSeeder`

**Opzione B — SQL puro:**
1. Apri `MLM_VERIFICATION_SEED.sql` in phpMyAdmin (tab "SQL", tutto in un
   colpo) oppure `mysql -u... nome_db < MLM_VERIFICATION_SEED.sql`.
   Usa variabili di sessione (`SET @u_... = LAST_INSERT_ID()`): non
   funziona se lo spezzetti in più connessioni separate.

Poi, con uno dei due metodi:
```
php artisan mlm:recalculate-points
php artisan mlm:calculate-weekly-bonuses
php artisan mlm:calculate-commissions
```
e confronta l'output/i dati con questo file.

**Per ripulire** (entrambe le opzioni): `DELETE FROM users WHERE email LIKE 'mlmverify-%';` — tutto il resto (conti, albero, punti, base commissioni, grant) ha `cascadeOnDelete` sull'utente e si pulisce da solo.

**Nota sulla radice**: questo albero nasce come radice indipendente (nessuno
sponsor). Se hai già una radice di sistema designata (Impostazioni MLM), i
33 agenti restano un albero separato — va benissimo per verificare bonus e
commissioni in isolamento. Se preferisci vederlo agganciato sotto la tua
radice reale, spostalo dopo l'import con "Sposta agente" in admin (sposta
`MLM-mgr`), oppure dimmelo e adatto lo script.

---

## 1. L'albero (in breve)

Un'unica "spina dorsale" verticale che attraversa **tutti e 5 i gradi con
bonus di struttura**, così lo stesso albero copre sia la cascata BasiQ sia
le commissioni indirette su tutti i livelli:

```
Manager (mgr)                         200 pt propri, 1 cliente (dep. 1.000€)
 └─ SuperVisor (sv)                   200 pt propri, 1 cliente
     └─ Top (top)                    200 pt propri, 1 cliente
         └─ Senior (sr)               200 pt propri, 1 cliente
             └─ Key (key)             200 pt propri, 1 cliente
                 ├─ basiq_demo        12 pt (registrazione), attivato 5gg fa
                 │                    → CANDIDATO REALE A BasiQ
                 ├─ chain_1 .. chain_10   coda per i livelli indiretti 6+
                 │   (chain_4 = grado 'top', per il test del "blocco")
                 └─ key_c1..3         3 figli 'basic' reali (gating liv. V)
    + mgr_c1..3, sv_c1..3, top_c1..3, sr_c1..3: 3 figli 'basic' reali per
      ciascun nodo della spina (stesso scopo + test dei tagli % diretti)
    + tier0, tier5: 2 agenti sotto mgr con 0 e 6 punti (test tagli 0%/5%)
```

Ogni agente ha **esattamente 1 cliente con un deposito da 1.000,00 €**
(margine KNM 30% → base Prov K = **300,00 €**), tranne `basiq_demo` (solo
registrazione, niente deposito) e `tier0`/`tier5` (0 e 6 punti propri).

`mgr`/`sv`/`top`/`sr`/`key` hanno inoltre dei "grant" (omaggio admin,
`mlm_metric_grants`) che coprono per intero i requisiti strutturali del
proprio grado (clienti, colonne, Basic al 1° livello): **il grado regge
anche dopo che il motore lo ricalcola per davvero** dalla struttura vera
(verificato: 0 promozioni, 0 retrocessioni su tutti i 33 agenti, ad ogni
esecuzione). I 3 figli 'basic' reali di ciascun nodo (key_c1..3 ecc.) invece
servono al **motore commissioni**, che il conteggio "Basic al 1° livello"
per il gating dei livelli indiretti lo fa SEMPRE dalla struttura vera, mai
dai grant.

---

## 2. Cascata bonus BasiQ (verificata §6.4 di MLM_PROPOSAL.md, estesa a Manager)

Dopo `mlm:recalculate-points`, `basiq_demo` diventa BasiQ (12 punti raggiunti
entro 30gg dall'attivazione — rilevato dal comando reale, non forzato dal
seed). Dopo `mlm:calculate-weekly-bonuses`, la cascata sulla sua upline:

| Beneficiario | Grado al momento | Importo | Come |
|---|---|---|---|
| MLM-key | Key | **60,00 €** | 60 − 0 (primo bonus-eligibile della catena) |
| MLM-sr | Senior | **50,00 €** | 110 − 60 |
| MLM-top | Top | **40,00 €** | 150 − 110 |
| MLM-sv | SuperVisor | **30,00 €** | 180 − 150 |
| MLM-mgr | Manager | **20,00 €** | 200 − 180 |
| **Totale** | | **200,00 €** | = importo della qualifica più alta in catena (Manager) |

---

## 3. Bonus Diretti KNM (effetto collaterale atteso, non un bug)

Lo stesso `mlm:calculate-weekly-bonuses` premia anche le soglie di punti
attivi (4pt→200€, 6pt→300€, 12pt→400€, cumulative, una tantum a vita) per
**qualunque** agente che le raggiunge — compresi tutti quelli di questo seed
con punti ≥ 6. Non è specifico alla cascata BasiQ: è solo un'altra regola
reale che gira nello stesso comando, e con questo seed viene esercitata su
quasi tutti gli agenti. Atteso:

- **22 agenti con ≥ 12 punti** (mgr, sv, top, sr, key, i 15 filler
  strutturali, basiq_demo, chain_4): **900,00 €** ciascuno (tutte e 3 le
  soglie) = 19.800,00 €
- **MLM-tier5** (6 punti): **500,00 €** (solo le prime 2 soglie)
- **Totale Bonus Diretti KNM: 20.300,00 €**

---

## 4. Commissioni dirette (tutti gli 8 tagli della tabella §5.1)

Base 300,00 € per tutti (deposito 1.000 € × margine 30%). Diretta = base × %.

| Punti personali | % | Agente/i che lo dimostrano | Commissione |
|---|---|---|---|
| 0 | 0% | MLM-tier0 | **0,00 €** (nessuna riga generata: percentuale 0 = niente da pagare) |
| 6 | 5% | MLM-tier5 | 15,00 € |
| 12 | 10% | mgr_c1, top_c1, key_c1 | 30,00 € ciascuno |
| 24 | 15% | mgr_c2, top_c2, key_c2 | 45,00 € ciascuno |
| 48 | 20% | mgr_c3, top_c3, key_c3 | 60,00 € ciascuno |
| 96 | 25% | sv_c1, sr_c1 | 75,00 € ciascuno |
| 150 | 30% | sv_c2, sr_c2 | 90,00 € ciascuno |
| 200 | 40% | mgr, sv, top, sr, key, sv_c3, sr_c3, chain_4 | 120,00 € ciascuno |

Totale commissioni dirette: **1.710,00 €** (22 righe — tier0 non genera riga).

---

## 5. Commissioni indirette (livelli 1-14, blocco per grado pari/superiore)

Tabella livello per livello, presa dalle righe reali di `mlm_commissions`
(non ricalcolata a mano). "Da chi" = il/i discendente/i a quel livello
relativo, ciascuno con 1 cliente da 300 € di base (tranne dove indicato).

### MLM-key (Key — NON esteso: si ferma al 5° livello, nessuna riga oltre)
| Livello | % | Da chi | Importo |
|---|---|---|---|
| 1 | 4% | chain_1, key_c1, key_c2, key_c3 (4 clienti) | 48,00 € |
| 2 | 2% | chain_2 | 6,00 € |
| 3 | 1% | chain_3 | 3,00 € |
| 4 | 0,5% | chain_4 | 1,50 € |
| 5 | 8% | chain_5 | 24,00 € |
| **Totale indiretta key** | | | **82,50 €** |

### MLM-sr (Senior — NON esteso: si ferma al 5° livello, nessuna riga oltre)
| Livello | % | Da chi | Importo |
|---|---|---|---|
| 1 | 4% | key, sr_c1, sr_c2, sr_c3 (4 clienti) | 48,00 € |
| 2 | 2% | chain_1, key_c1, key_c2, key_c3 (4 clienti) | 24,00 € |
| 3 | 1% | chain_2 | 3,00 € |
| 4 | 0,5% | chain_3 | 1,50 € |
| 5 | 8% | chain_4 | 24,00 € |
| **Totale indiretta sr** | | | **100,50 €** |

### MLM-top (Top — ESTESO: 0,5% dal 6° livello, MA bloccato da chain_4)
| Livello | % | Da chi | Importo |
|---|---|---|---|
| 1 | 4% | sr, top_c1, top_c2, top_c3 (4 clienti) | 48,00 € |
| 2 | 2% | key, sr_c1, sr_c2, sr_c3 (4 clienti) | 24,00 € |
| 3 | 1% | chain_1, key_c1, key_c2, key_c3 (4 clienti) | 12,00 € |
| 4 | 0,5% | chain_2 | 1,50 € |
| 5 | 8% | chain_3 | 24,00 € |
| 6 | 0,5% | chain_4 (**grado 'top' = pari al beneficiario → blocca da qui**) | 1,50 € |
| 7-11 | 0,5% | chain_5, chain_6, chain_7, chain_8, chain_9 (5 livelli) | 5 × 1,50 = 7,50 € |
| 12+ | — | chain_10: **NESSUNA riga** (oltre il livello 11 dal blocco, si ferma) | 0,00 € |
| **Totale indiretta top** | | | **118,50 €** |

### MLM-sv (SuperVisor — ESTESO, MAI bloccato: chain_4 è 'top', grado
inferiore a SuperVisor, non blocca nulla)
| Livello | % | Da chi | Importo |
|---|---|---|---|
| 1 | 4% | sv_c1, sv_c2, sv_c3, top (4 clienti) | 48,00 € |
| 2 | 2% | sr, top_c1, top_c2, top_c3 (4 clienti) | 24,00 € |
| 3 | 1% | key, sr_c1, sr_c2, sr_c3 (4 clienti) | 12,00 € |
| 4 | 0,5% | chain_1, key_c1, key_c2, key_c3 (4 clienti) | 6,00 € |
| 5 | 8% | chain_2 | 24,00 € |
| 6-13 | 0,5% | chain_3..chain_10 (8 livelli, MAI bloccato) | 8 × 1,50 = 12,00 € |
| **Totale indiretta sv** | | | **126,00 €** |

### MLM-mgr (Manager — ESTESO, MAI bloccato: nessun altro Manager in downline)
| Livello | % | Da chi | Importo |
|---|---|---|---|
| 1 | 4% | mgr_c1, mgr_c2, mgr_c3, sv, **tier0, tier5** (6 clienti — tier0/tier5 sono figli diretti di mgr) | 72,00 € |
| 2 | 2% | sv_c1, sv_c2, sv_c3, top (4 clienti) | 24,00 € |
| 3 | 1% | sr, top_c1, top_c2, top_c3 (4 clienti) | 12,00 € |
| 4 | 0,5% | key, sr_c1, sr_c2, sr_c3 (4 clienti) | 6,00 € |
| 5 | 8% | chain_1, key_c1, key_c2, key_c3 (4 clienti) | 96,00 € |
| 6-14 | 0,5% | chain_2..chain_10 (9 livelli, MAI bloccato) | 9 × 1,50 = 13,50 € |
| **Totale indiretta mgr** | | | **223,50 €** |

### MLM-chain_4 (Top "bloccante", genera a sua volta le sue commissioni)
Gating livello 1 (0 Basic richiesti: sempre soddisfatto) → 4% su chain_5 =
12,00 €. Livelli 2-5: chain_4 ha 0 figli reali "Basic+" quindi il gating
fallisce (richiede 2-3 Basic al 1° livello) → 0 €. Dal livello 6 (chain_10,
esteso, nessun gating): 0,5% = 1,50 €.
→ **Totale (diretta 120 + indiretta 13,50): 133,50 €**

---

## 6. Riepilogo totali per agente (diretta + indiretta)

| Agente | Diretta | Indiretta | Totale |
|---|---|---|---|
| MLM-mgr | 120,00 | 223,50 | **343,50** |
| MLM-sv | 120,00 | 126,00 | **246,00** |
| MLM-top | 120,00 | 118,50 | **238,50** |
| MLM-sr | 120,00 | 100,50 | **220,50** |
| MLM-key | 120,00 | 82,50 | **202,50** |
| MLM-chain_4 | 120,00 | 13,50 | **133,50** |
| mgr_c1 / top_c1 / key_c1 | 30,00 | 0,00 | 30,00 (× 3) |
| mgr_c2 / top_c2 / key_c2 | 45,00 | 0,00 | 45,00 (× 3) |
| mgr_c3 / top_c3 / key_c3 | 60,00 | 0,00 | 60,00 (× 3) |
| sv_c1 / sr_c1 | 75,00 | 0,00 | 75,00 (× 2) |
| sv_c2 / sr_c2 | 90,00 | 0,00 | 90,00 (× 2) |
| sv_c3 / sr_c3 | 120,00 | 0,00 | 120,00 (× 2) |
| tier5 | 15,00 | 0,00 | 15,00 |
| tier0 | 0,00 | 0,00 | 0,00 |

**Totale generale commissioni (dirette + indirette): 2.374,50 €**
(22 righe dirette + 97 righe indirette = 119 righe in `mlm_commissions`)

---

## 7. Checklist rapida dopo l'import

- [ ] `mlm:recalculate-points` → "1 nuovi BasiQ rilevati", "0 promozioni, 0 retrocessioni"
- [ ] `mlm:calculate-weekly-bonuses` → "1 eventi BasiQ elaborati", cascata Key 60/Senior 50/Top 40/SuperVisor 30/Manager 20
- [ ] `mlm:calculate-commissions` → "22 commissioni dirette, 97 indirette, totale 2.374,50 EUR"
- [ ] Se uno di questi numeri cambia dopo una modifica al codice del motore MLM, sai subito che qualcosa nella logica è cambiato (voluto o no).
