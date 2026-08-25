# KMoney — Strategia operativa di lancio e crescita
## Reggio Calabria, prima microarea · Audit software + piano commerciale + compliance

**Data:** 14 agosto 2026
**Destinataria:** Laura — agente KMoney, Reggio Calabria
**Base:** repository `kmoney-app` ispezionato il 14/08/2026 (branch locale su `C:\laragon\www\kmoney-app`), documentazione di progetto, ricerca su fonti esterne
**Stato:** documento di analisi e proposta — **nessuna riga di codice è stata modificata, nessuna migration eseguita**

---

## Legenda delle fonti

Ogni affermazione rilevante è marcata:

| Marca | Significato |
|---|---|
| `[CODICE]` | Verificato leggendo il file sorgente indicato |
| `[DOC]` | Da documentazione interna del progetto (`.md` nel repo) |
| `[FONTE]` | Fonte esterna verificabile, URL indicato |
| `[TUA INFO]` | Informazione contenuta nel tuo prompt, non verificabile nel codice |
| `[IPOTESI]` | Mia assunzione dichiarata, da confermare |
| `[RACCOMANDAZIONE]` | Mia proposta operativa |

---

# 1. Sintesi esecutiva

**Il software è molto più avanti della strategia commerciale.** KMoney non è un prototipo: è una piattaforma Laravel 12 con 163 migration, 23 service class, 95 file di test, doppia contabilità con verifica di integrità notturna, KYC, carte NFC, QR dinamico, integrazioni WooCommerce/Magento e un motore MLM completo (punti, qualifiche, commissioni dirette/indirette a N livelli, bonus di struttura, simulatore admin) `[CODICE]`. Il collo di bottiglia non è tecnico: è **densità di accettazione a Reggio Calabria** e **tenuta legale del modello agenti**.

**Cinque conclusioni che cambiano il piano:**

**1) Il "25% in più" NON è uno sconto del 25%. È uno sconto del 20%, e solo sulla quota del prezzo che l'esercente accetta in Ky.**
100 € → 125 Ky significa pagare 100 € una cosa che ne vale 125: `1 − 100/125 = 20%`. E poiché nel codice ogni azienda dichiara la propria percentuale accettata (`companies.accepted_ky_percentage` ∈ {0, 25, 50, 75, 100}) e ogni prodotto la propria (`listings.ky_percentage` ∈ {25, 50, 75, 100}) `[CODICE]`, il vantaggio reale sullo scontrino è `quota_Ky × 20%`. Su un esercente al 25% il cliente risparmia **5 € ogni 100 € di spesa**, non 25. Comunicare "25% di sconto" è, oltre che matematicamente falso, un rischio diretto ai sensi degli artt. 21-23 del Codice del Consumo. **La sezione 7 contiene la formulazione corretta, pronta all'uso.**

**2) Oggi l'agente viene pagato quando il cliente *ricarica*, non quando *spende*.**
La base commissionabile è la ricarica (`MlmPointsService::createCommissionBaseEntry()` scrive l'importo del deposito in `mlm_commission_base_ledger`) `[CODICE]`. Nessuna commissione è legata alle transazioni presso gli esercenti. Effetto: il piano incentiva a caricare wallet, non a farli usare — l'opposto di ciò che rende vivo un circuito, e la ragione per cui i wallet muoiono con saldi fermi. **È la modifica di prodotto più importante che raccomando (backlog P0-3).**

**3) Il rischio di compliance più serio non è teorico: è già nel codice e ha un precedente sanzionato da 3,2 milioni di euro.**
`RecalculateMlmPoints` promuove un agente a **BasiQ** con 12 punti attivi *qualunque sia la loro origine* — e la sola registrazione di un cliente vale 1 punto (`mlm_point_rules`, riga `registration`) `[CODICE]`. Dodici iscrizioni gratuite, nessun euro di fatturato, evento BasiQ generato. La correzione era già stata raccomandata internamente il 24/07/2026 (`ANALISI_MLM_SOSTENIBILITA_2026-07-24.md`, §4.3) e **non è stata implementata**: `min_paying_clients` non esiste in nessuna migration, e il gate sui punti da ricarica per BasiQ non c'è `[CODICE]`. Attenuante importante: i Bonus Diretti (900 €) sono stati **disattivati** il 14/08/2026 (`mlm_direct_bonuses_enabled` default `false`) `[CODICE]`, il che riduce il danno da ~1.100 € a ≤200 € per evento — ma non chiude la porta. Il precedente rilevante è **AGCM PS11086 Lyoness, 19/12/2018, 3.200.000 € di sanzione**, dove il cashback fu giudicato «aspetto secondario» rispetto ai flussi generati dai versamenti degli affiliati `[FONTE agcm.it]`.

**4) I 480 € vanno riprogettati prima di essere proposti a chiunque.**
Non esiste alcuna soglia in euro nella L. 173/2005 — i criteri sono **qualitativi** `[FONTE Normattiva]`: art. 6 lett. b) rende indiziante di piramidalità la somma «di rilevante entità **in assenza di una reale controprestazione**» chiesta al reclutato; lett. c) i «materiali didattici e corsi di formazione **non strettamente inerenti e necessari** … e non proporzionati al volume dell'attività». La struttura difendibile esiste ed è nella **sezione 9**: pacchetto **facoltativo**, mai condizione per operare né per incassare provvigioni, con listino analitico, recesso 10 giorni lavorativi (art. 4 c. 3), riacquisto al 90% (art. 4 c. 6), **zero provvigioni alla upline sul pagamento stesso**.

**5) Il modello aziendale supera lo stress test dei 6 mesi senza nuovi agenti — ma il modello di reddito dell'agente no.**
Senza nuovi ingressi: i bonus di struttura si azzerano (dipendono da eventi BasiQ), gli Extra Bonus quasi (le qualifiche richiedono Basic al 1° livello), i Bonus Diretti sono già spenti; le commissioni su Prov K continuano a maturare sulle ricariche. **KMoney sta finanziariamente meglio** (+1.266 € su sei mesi, foglio *Stress test*). L'agente, invece, perde la parte più visibile del proprio reddito. Questo *è* il test dell'art. 5 L. 173/2005: se il guadagno crolla quando smetti di reclutare, l'incentivo primario è il reclutamento. La risposta corretta non è legale, è di prodotto: spostare peso dai bonus di struttura alla **commissione ricorrente su transazioni reali** (P0-3).

**Cosa faccio adesso se sei d'accordo:** i tre blocchi P0 della sezione 20 — gate BasiQ su punti da ricarica, tetto di sicurezza sulle commissioni, segregazione dei Ky promozionali — sono ~3-4 giorni di lavoro e vanno fatti *prima* del primo agente reclutato e *prima* di qualunque comunicazione pubblica sul 25%.

---

# 2. Elementi verificati nel codice

Elenco esatto di ciò che ho aperto e letto. Tutto ciò che segue in questo documento e che riguarda il software si appoggia solo a questi file.

## 2.1 Stack e configurazione

| Elemento | Verificato | Valore |
|---|---|---|
| `composer.json` | ✅ | PHP ^8.2, `laravel/framework` ^12.0, `laravel/reverb` ^1.0, `stripe/stripe-php`, `barryvdh/laravel-dompdf` ^3.0, `simplesoftwareio/simple-qrcode` ^4.2, `minishlink/web-push` 9.0, `sentry/sentry-laravel`, `web-auth/webauthn-lib` ^4.9 |
| Dev | ✅ | PHPUnit ^11.5, Larastan ^3.0, Pint, Faker, Playwright (`playwright.config.js`) |
| `bootstrap/app.php` | ✅ | Middleware alias: `onboarding`, `twofactor`, `api.token`, `not.suspended`, `step.up`, `contract`, `agent.contract`, `backoffice`, `mlm.enabled`. CSP custom. Sentry condizionale. Health endpoint `/up` |
| `routes/console.php` | ✅ | 11 job schedulati (dettaglio §2.6) |
| Pannello admin | ✅ | **NON è Filament** — è un backoffice Blade custom sotto `/admin/*` (~120 route) con middleware `backoffice` |

**Nota:** il tuo prompt cita "pannelli Filament" `[TUA INFO]`. Nel repository **Filament non è installato** `[CODICE: composer.json]`. Tutta l'amministrazione è in `app/Http/Controllers/Admin/` + `resources/views/admin/`. Non è un difetto — ma cambia le stime di ogni nuova pagina admin: non si scaffolda, si scrive.

## 2.2 Migration e schema (163 file in `database/migrations/`)

Tabelle verificate direttamente, rilevanti per la strategia:

| Tabella | Migration | Contenuto chiave |
|---|---|---|
| `ky_cards` | `2026_05_28_300000` + `2026_07_22_120000` | `price_eur_cents`, `bonus_type` ∈ {fixed, percentage}, `ky_base_amount`, `bonus_value`, `mlm_points`, `mlm_points_duration_days`, `is_active`, `stripe_price_id` |
| `ky_card_purchases` | `2026_05_28_300100` | snapshot prezzo e Ky accreditati, stato pending→completed, `stripe_checkout_session_id`, `transfer_id` |
| `listings` | `2026_05_26_110000` + 4 alter | `ky_percentage` ∈ {25,50,75,100} (default 100), `desired_ky_percentage`, `delivery_type`, `subcategory` |
| `listing_offers` | `2026_08_13_110000` | offerte a tempo con `full_price_ky_snapshot`, `offer_price_ky`, `expires_at`, `cancelled_at`, mai cancellate fisicamente |
| `companies` | 12 alter | `accepted_ky_percentage` ∈ {0,25,50,75,100}, `sector_id`, `latitude`/`longitude`/`geocoded_at`, `plan_id`, `suspended_at`, `payments_paused` |
| `kyc_documents` | `2026_05_26_140000` | tipo, file, stato pending/accepted/rejected, revisore, note admin; + `companies.kyc_status/kyc_notes/kyc_reviewed_by/kyc_reviewed_at` |
| `users` (referral) | `2026_06_10_100000` | `referral_code` (12 char, unique), `referred_by_user_id` |
| `users` (referral bonus) | `2026_07_27_190100` | `referral_bonus_paid_amount`, `referral_bonus_tier` |
| `system_settings` | ~10 alter | `welcome_bonus_amount`, `referral_bonus_{amico,agente,attivita}_amount` (default 1000/5000/10000 cent Ky), `mlm_knm_margin_percent`, `mlm_payout_threshold_eur_cents`, `mlm_root_agent_id`, `mlm_points_validity_override_minutes`, **`mlm_direct_bonuses_enabled` default `false`** |
| `mlm_rank_requirements` | `2026_07_13_210000` + `2026_07_22_110000` + `2026_07_27_120000` | `min_points`, `min_clients`, **`min_deposit_points`**, `min_level1_basic`, `min_branches_with_{key,senior,top,supervisor}`, `min_branches_300pt` |
| `mlm_point_rules` | `2026_07_22_100000` | regole punti per evento (riga `registration`) |
| `mlm_point_ledger` | `2026_07_02_090200` + 2 alter | punti con finestra `valid_from`/`valid_until`, `source_type` ∈ {registration, deposit}, DECIMAL |
| `mlm_commission_base_ledger` | `2026_07_02_091000` + `2026_07_16_100000` | `monthly_amount_eur_cents`, **`knm_margin_percent` (snapshot)**, finestra validità |
| `mlm_agent_closure` | `2026_07_02_090100` | closure table dell'albero agenti |
| `mlm_wallet_ledger_entries` | `2026_07_30_100000` | "cassetto kmoney": compensi accreditati **in Ky**, 4 categorie |
| `mlm_metric_grants` | `2026_07_14_090000` + 3 alter | metriche "omaggio" assegnabili da admin (anche negative) |
| `nfc_cards`, `nfc_card_logs`, `nfc_card_auth_sessions` | `2026_05_29_7000*` | carte NFC con PIN, soglia PIN, spedizione, revoca |
| `transaction_fees` | `2026_05_29_400300` | fee per `operation_kind`, flat o percentage, min/max |
| `cashback_rules` | `2026_05_27_540000` + `550000` | regole cashback con targeting azienda/privato/utente |
| `audit_logs` | `2026_04_02_000600` + `2026_06_23_120000` | audit trail con indice antifrode |
| `company_reports` | `2026_07_29_150000` | "segnala azienda" — segnalazione esercenti |

## 2.3 Service class (23 in `app/Services/`)

| Classe | Letta | Ruolo verificato |
|---|---|---|
| `MlmCommissionEngine` | ✅ integrale | Commissioni mensili dirette + indirette |
| `MlmPointsService` | ✅ integrale | Assegnazione punti registrazione/deposito + base commissionabile |
| `MlmRankEngine` | ✅ (200 righe) | Valutazione qualifiche, promozione **e retrocessione** |
| `MlmBonusService` | ✅ (150 righe) | Cascata bonus di struttura su evento BasiQ |
| `MlmWalletService` | ✅ (90 righe) | "Cassetto kmoney": accredito compensi **in Ky** |
| `MlmPayoutService` | ✅ (60 righe) | Aggregazione compensi → liquidazione EUR, stati pending→approved→paid |
| `ReferralBonusService` | ✅ integrale | Bonus segnalazione 3 livelli, non cumulativi |
| `CashbackService` | ✅ (120 righe) | Cashback su transfer, finanziato dal conto sistema |
| `NettingService` | ✅ (40 righe) | Compensazione crediti incrociati tra aziende |
| `MlmAwardService`, `MlmTreeService`, `MlmSimulationService`, `TransferBookingService`, `GeocodingService` (Nominatim), `MenuVisibilityService`, `PaymentPlanService`, `PlanUpgradeService`, `ScheduledPaymentService`, `SubAccountService`, `TestDataPurgeService`, `CompanyReportService`, `WebhookService`, `WebPushService` | esistenza + firma | — |

## 2.4 Il motore MLM: numeri esatti letti nel codice

**Commissioni dirette** (`MlmCommissionEngine::DIRECT_TABLE`) — % su Prov K in base ai punti attivi dell'agente:

| Punti attivi | % |
|---|---|
| ≥ 200 | 40% |
| ≥ 150 | 30% |
| ≥ 96 | 25% |
| ≥ 48 | 20% |
| ≥ 24 | 15% |
| ≥ 12 | 10% |
| ≥ 6 | 5% |
| < 6 | 0% |

**Commissioni indirette** (`INDIRECT_PERCENTAGES`): L1 4% · L2 2% · L3 1% · L4 0,5% · **L5 8%** · L6+ 0,5% (solo Top/SuperVisor/Manager, con stop 5 livelli sotto il primo pari-o-superior grado incontrato nel ramo).

**Gating indirette** (`INDIRECT_REQUIREMENTS`) — livello pagato solo se il beneficiario ha in proprio: L1 12pt/0 Basic · L2 12pt/2 Basic · L3 24pt/2 Basic · L4 24pt/2 Basic · L5 48pt/3 Basic.

**Base di calcolo:** `Prov K = importo_ricarica × mlm_knm_margin_percent / 100` (default 30%, snapshot per deposito). **Non** l'importo pieno.

**Bonus di struttura** (`MlmBonusService::BONUS_AMOUNTS_EUR_CENTS`): Key 60 € · Senior 110 € · Top 150 € · SuperVisor 180 € · Manager 200 €. Distribuzione "per posizione": `payout = max(0, importo_proprio_grado − max importo fra i bonus-eligibili sotto di lui)`. **La somma dei payout di una catena è sempre pari all'importo del grado più alto presente** → costo per evento BasiQ **strutturalmente limitato a 200 €**, qualunque sia la profondità della rete.

**Qualifiche** (default seedati in `mlm_rank_requirements`, editabili da `/admin/mlm-impostazioni`):

| Grado | Punti | Clienti | Punti da ricarica | Basic L1 | Struttura |
|---|---|---|---|---|---|
| Basic | 12 | 6 | 6 | — | — |
| Key | 24 | 12 | 12 | 2 | — |
| Senior | 48 | 24 | 24 | 3 | 2 Key su 2 colonne |
| Top | 48 | 24 | 24 | 4 | 3 colonne ≥300 pt |
| SuperVisor | 48 | 24 | 24 | 5 | 2 Senior + 2 Top su 4 colonne |
| Manager | 48 | 24 | 24 | 6 | 3 SuperVisor su 3 colonne |

**Retrocessione attiva:** i punti scadono (`valid_until`) e `MlmRankEngine::syncRank()` allinea il grado in entrambe le direzioni, fino a `start`, senza periodo di grazia. Valutazione dal basso verso l'alto. Nessun ricalcolo retroattivo di bonus già erogati.

**BasiQ:** agente con `mlm_activated_at` negli ultimi 30 giorni e `mlmRealActivePoints() >= 12` → `mlm_basiq_at` = now, `mlm_basiq_bonus_eligible` = true `[CODICE: RecalculateMlmPoints::handle()]`.

## 2.5 Referral — cosa c'è davvero

`ReferralBonusService`, **indipendente dall'MLM** (funziona anche con `mlm_enabled=false`). Tre livelli **dedotti automaticamente da cosa fa l'invitato**, mai dichiarati a monte:

| Livello | Trigger | Default |
|---|---|---|
| `amico` | invitato si registra come privato | 10,00 Ky — **erogato subito alla registrazione** |
| `agente` | invitato firma il contratto di nomina agente | 50,00 Ky |
| `attivita` | azienda dell'invitato ottiene KYC approvato | 100,00 Ky |

Non cumulativi: si eroga solo la differenza fino al livello più alto. Idempotente (`idempotency_key`). **Chi paga:** livelli `amico` e `agente` sono addebitati al **conto dell'agente di riferimento del segnalante**, anche in scoperto oltre il fido (bypass esplicito via super-admin initiator); `attivita` è a carico del conto sistema. Il bonus spetta solo a segnalanti **privati non agenti** (`referrerIsEligible()`).

## 2.6 Job schedulati (`routes/console.php`)

| Comando/Job | Cadenza | Condizione |
|---|---|---|
| `mlm:recalculate-points` | 03:00 giornaliero | `config('kmoney.mlm_enabled')` |
| `mlm:calculate-commissions` | 1° del mese 02:00 | idem |
| `mlm:calculate-weekly-bonuses` | mercoledì 04:00 | idem |
| `accounting:verify-integrity` | 02:00 giornaliero (completa) + oraria (`--quick`) | sempre |
| `accounting:check-contention` | ogni 15 min | sempre |
| `ProcessDueInstallments` | 06:00 | — |
| `ExpirePaymentRequests` | ogni minuto | — |
| `RemindPaymentRequests` | ogni 5 min | — |
| `payments:run-scheduled` | ogni minuto | — |
| `SendMonthlyStatements` | 1° del mese 08:00 | — |
| `CheckBalanceAlerts` | oraria | — |
| `queue:work --stop-when-empty` | ogni minuto | compatibilità hosting shared |

## 2.7 Test

**95 file** (93 in `tests/Feature`, 2 in `tests/Unit`). Copertura MLM notevolmente densa: `MlmCommissionEngineTest`, `MlmRankEngineTest`, `MlmBonusServiceTest`, `MlmAwardServiceTest`, `MlmPointsServiceTest`, `MlmPayoutServiceTest`, `MlmWalletServiceTest`, `MlmTreeServiceTest`, `MlmSimulatorTest`, **`MlmSlideCompensationTablesTest`** (riproduce al centesimo le 4 tabelle delle slide KNM), `RecalculateMlmPointsCommandTest`, `CalculateMlmWeeklyBonusesCommandTest`, `CancelMlmDirectBonusesCommandTest`, `ReferralBonusServiceTest`, `KycControllerTest`, `KyCardCreditTest`, `CompanyKyAcceptanceTest`, `OnboardingControllerTest`.

**Non ho eseguito la suite** (l'ambiente bridge non ha PHP; l'ultimo dato noto da memoria di progetto è 839 passed / 6 failure pre-esistenti in clone cloud, 14/08).

## 2.8 Funzionalità commerciali già presenti (spesso sottovalutate)

| Funzione | Route | Nota |
|---|---|---|
| **Directory pubblica esercenti** | `/aziende`, `/aziende/{slug}` | con filtro `%Kmoney` esatta/minima |
| **Mappa esercenti** | idem | `latitude`/`longitude` + `GeocodingService` (Nominatim) |
| **Kit merchant** | `/kit-merchant`, `/kit-merchant/qr-pdf` | QR del negozio in PDF — **l'equivalente della vetrofania Satispay** |
| **Incasso multi-modale** | `/incassa/{qr,nfc,codice,sonic}` | QR dinamico, NFC, codice, ultrasuoni |
| **Offerte a tempo** | `/admin/listings/offerte` | prezzo pieno fotografato, scadenza automatica |
| **Link di pagamento** | `/link-pagamento` | condivisibile |
| **Segnala azienda** | `/admin/segnalazioni-aziende` | pipeline segnalazione → contratto firmato |
| **Invita** | `/invita` | link + codice referral personale |
| **Simulatore MLM** | `/admin/mlm-simulatore` | simula ricarica e BasiQ |
| **Report MLM** | `/admin/mlm-report`, `/admin/mlm-report/{user}` + export | — |
| **Albero agenti** | `/admin/mlm-albero` + spostamento nodo | — |
| **Visibilità menu** | `/admin/menu-visibility` | accendi/spegni voci per ruolo |
| **E-commerce** | plugin WooCommerce + modulo Magento 2 | `.zip` nel repo, doc dedicata |
---

# 3. Elementi non trovati o incerti

Cose che il tuo prompt dà per presenti e che **non ho trovato**, o che ho trovato in forma diversa. Ogni riga è un'ipotesi da correggere prima di costruirci sopra la comunicazione.

## 3.1 Divergenze fra prompt e codice

| Nel tuo prompt `[TUA INFO]` | Cosa dice il codice `[CODICE]` | Impatto |
|---|---|---|
| "pannelli Filament" | Filament **non installato**. Backoffice Blade custom | Ogni nuova pagina admin va scritta a mano: +1-2 gg per pagina rispetto a uno scaffold |
| "riconoscimento di un 25% aggiuntivo … 100 € → 125 Ky" | **Nessun 25% hardcoded.** `ky_cards` ha `bonus_type` ∈ {fixed, percentage} e `bonus_value` **liberi per card**. Il "+25%" appare solo come *esempio* nel form admin (`admin/ky-cards/form.blade.php`) | Il 25% è una **scelta di catalogo**, non una regola di prodotto. Va deciso, documentato e reso coerente su tutti i tagli |
| "acquisti presso esercenti convenzionati" con vantaggio del 25% | Il vantaggio è **tagliato due volte**: da `companies.accepted_ky_percentage` e da `listings.ky_percentage` | Il beneficio reale per il cliente è **variabile per esercente e per prodotto**. Non è comunicabile come numero unico |
| "requisito minimo di clienti per alcuni avanzamenti" | ✅ **Confermato**: `mlm_rank_requirements.min_clients` (6/12/24/24/24/24) e `min_deposit_points` (6/12/24/24/24/24) | OK |
| "MLM_PROPOSAL.md" | ✅ Esiste, 27 KB, v1.0 del 01/07/2026, aggiornato fino al 30/07 | OK — ma contiene **7 punti dichiarati "da confermare"**, di cui alcuni ancora aperti (§3.3) |
| "trasferimenti tra utenti" | ✅ `/invia`, `TransferBookingService`, con fido, massimali, PIN, TOTP sopra soglia | OK |
| "carte NFC, `ky_cards`" | Attenzione: **sono due cose diverse**. `ky_cards` = **tagli di ricarica** (pacchetti EUR→Ky). Le carte fisiche NFC sono `nfc_cards` | Errore di comunicazione facile da fare con clienti ed esercenti |

## 3.2 Funzionalità citate nel prompt e assenti dal codice

Ho cercato in `app/` e `resources/views/`:

| Funzione | Stato | Nota |
|---|---|---|
| **Academy / formazione in app** | ❌ assente | Nessuna route, nessun model |
| **Quiz / certificazione agente** | ❌ assente | — |
| **CRM agente** (pipeline lead) | ❌ assente | Esiste `/admin/mlm-report/{user}` e `/admin/mlm/assegnazione-clienti`, ma è reportistica, non pipeline |
| **Landing page personale dell'agente** | ❌ assente | Esiste solo `/invita` (link + codice) e `users.mlm_agent_code` |
| **Missioni settimanali** | ❌ assente | — |
| **Kit agente / kit dimostrativo** | ❌ assente lato software | Esiste `/kit-merchant` per l'**esercente** |
| **Rateizzazione dei 480 €** | ⚠️ parziale | Esiste `payment_plans` + `payment_plan_installments` + `ProcessDueInstallments` (rate giornaliere alle 06:00) e `scheduled_payments` con ricorrenza — ma sono pensati per pagamenti **tra utenti/aziende**, non per un pacchetto servizi venduto all'agente. Riusabili con adattamenti |
| **Fatture/ricevute agente** | ⚠️ parziale | Esistono ricevute di trasferimento (`/invia/ricevuta/{uuid}`) e note di credito. **Nessuna fatturazione né ritenuta d'acconto sui payout MLM**: `mlm_payouts` non ha campi imponibile/ritenuta/netto `[CODICE]` |
| **Antifrode** | ⚠️ minimo | Solo `audit_logs` con indice antifrode + `LoginLog` + rilevamento nuovo IP. **Nessuna regola antifrode sui referral** (self-referral, cluster IP/dispositivo, anelli) |
| **Simulatore sostenibilità piano commissionale** | ⚠️ parziale | `MlmSimulationService` + `/admin/mlm-simulatore` simulano *un agente*. Non esiste una simulazione **aggregata di cassa** |
| **Dashboard KPI di funnel** | ❌ assente | Esistono `/admin/analytics` e `/admin/report`, orientati a transazioni e conti — non al funnel cliente/esercente/agente |
| **Scadenza dei Ky** | ❌ assente | Il saldo Ky **non ha scadenza**: nessuna colonna di expiry sul saldo conto `[CODICE: Account, LedgerEntry]`. L'unico `expires_at` trovato riguarda le richieste di pagamento |
| **Segregazione Ky promozionali vs Ky acquistati** | ❌ assente | Il saldo è un unico numero. Il precedente tecnico riusabile esiste: `MlmWalletService::withdrawableBalance()` segrega già una quota del saldo agente `[CODICE]` |

## 3.3 Raccomandazioni interne mai implementate

`ANALISI_MLM_SOSTENIBILITA_2026-07-24.md` chiudeva con 6 azioni in ordine di urgenza `[DOC]`. Stato al 14/08/2026 verificato nel codice:

| # | Azione raccomandata | Stato | Verifica |
|---|---|---|---|
| 1 | **Gate su BasiQ**: ≥6 dei 12 punti da ricarica | ❌ **NON FATTO** | `RecalculateMlmPoints` usa `mlmRealActivePoints() >= 12` senza filtro su `source_type` |
| 2a | `min_deposit_points` sulle qualifiche | ✅ FATTO | migration `2026_07_27_120000` |
| 2b | `min_paying_clients` sulle qualifiche | ❌ **NON FATTO** | nessuna occorrenza in `app/` né `database/` |
| 3 | Parere legale L. 173/2005 | ❓ ignoto | non verificabile dal codice |
| 4 | **Tetto commissioni** (es. 80% di Prov K) | ❌ **NON FATTO** | nessun `min()` finale in `MlmCommissionEngine` |
| 5 | KYC **prima** dei punti registrazione | ❌ **NON FATTO** | `awardRegistrationPoints()` chiamato alla registrazione, `kyc_status='pending'` |
| 6 | Policy riserva di cassa payout | ❓ ignoto | nessuna traccia software |

Mitigazione parziale intervenuta dopo quel documento: **Bonus Diretti KNM disattivati** il 14/08/2026 (`mlm_direct_bonuses_enabled` default `false` + comando `mlm:cancel-direct-bonuses`) `[CODICE]`. Questo toglie 900 € dai 1.100 € del percorso a costo zero, ma **non chiude il percorso**.

## 3.4 Punti aperti in `MLM_PROPOSAL.md` §7

Dichiarati "bloccanti per l'implementazione" e ancora senza risposta esplicita `[DOC]`:
1. Cliente invitato da un altro **cliente** (non agente): l'evento risale al primo agente antenato o si perde? (proposta: risale)
2. Chi arriva a 12 punti **dopo** i 30 giorni: diventa Basic ma non genera mai bonus di struttura? (presunto sì)
3. Requisito Manager: slide dicono "3 SuperVisor", excel e messaggio dicono "3 Senior" — **2 fonti su 3 in disaccordo con l'implementazione attuale**

## 3.5 Anomalie di produzione note (da memoria di progetto, non ri-verificate oggi)

- `kmoney.it`: il codice viene deployato ma `php artisan migrate` **no** → 500 "Unknown column" ricorrenti; riparazione manuale via phpMyAdmin
- `rename_sectors` eseguita due volte: possibile azzeramento di `companies.sector` a 'Altro' — **APERTA**
- Due P.IVA diverse fra contratto e footer/ricevute — **APERTA**
- 5° livello compensi indiretti all'8% (anomalo rispetto alla curva 4/2/1/0,5) — verificato contro le slide, ma resta un'anomalia di disegno

---

# 4. Ricostruzione delle azioni iniziali di Satispay

Ricerca su fonti esterne, 14 ricerche e ~20 pagine lette. **Satispay non ha mai pubblicato un playbook**: molte tattiche di campo sono poco documentate. Dove non c'è fonte, lo dico.

## 4.1 Tabella delle azioni

| # | Periodo | Azione | Obiettivo | Risultato noto | Certezza |
|---|---|---|---|---|---|
| 1 | 2013 | Circuito proprietario su **IBAN**, non su Visa/Mastercard | Struttura di costo che rende sostenibile il micropagamento | Base di tutto il modello | DOCUMENTATO |
| 2 | 2014-15 | **Iccrea Banca** entra come investitore (5,5 M€) | Capitale + accesso alla rete BCC | Diventa il canale distributivo principale | PROBABILE (fonti in conflitto sulla data) |
| 3 | 2015 | App con **budget settimanale + ricarica automatica** (max 200 €/sett., ripristino del solo speso) | Ricorrenza strutturale, "salvadanaio" | 6 tx/mese/utente vs 2,8 delle carte | DOCUMENTATO |
| 4 | 2015 → 6/4/2025 | **Pricing esercente: 0 canone, 0 attivazione, 0% sotto 10 €, 0,20 € fisso sopra** | Rendere irrazionale il rifiuto | Modello tenuto 10 anni | DOCUMENTATO |
| 5 | 2015 | Onboarding **senza hardware**: QR + **kit vetrofanie gratuito spedito** | Attivazione a costo zero + visibilità in vetrina | Kit tuttora automatico | DOCUMENTATO |
| 6 | 2015-16 | **Densità geografica prima della scala**: Cuneo, Torino, Ravenna prima di Milano e Roma | Massa critica locale + errori a basso costo | Piemonte domina le classifiche 2020 (~30k esercenti) | DOCUMENTATO |
| 7 | 2015-16 | **Alleanza con la BCC locale** (caso Ravenna) | Fiducia di territorio verso i correntisti-esercenti | Ravenna 2022: **1 maggiorenne su 4**, **36%** degli esercenti target, **11 tx/mese** per utente, **180 tx/mese** per esercente | DOCUMENTATO |
| 8 | giu 2016 | Accordo **Iccrea + Ingenico**: Satispay dentro i POS BCC | Scalare oltre il porta-a-porta | 83.000 POS abilitati; 1,2 M utenti attivabili in 1 click da home banking | DOCUMENTATO |
| 9 | ott 2016 – gen 2017 | **"La prima volta non si scorda mai"**: OOH su tram/pensiline a Milano e Torino + **10% cashback sul primo acquisto** presso 1.600+ esercenti | Rompere la barriera del **primo utilizzo** | Razionale dichiarato: chi fa il primo pagamento resta | DOCUMENTATO |
| 10 | mar 2017 | 30 assunzioni con **city manager** a Roma, Torino, Verona, Padova, Vicenza, Ravenna, Bologna, Taranto, Bari | Presidio commerciale città per città | 12.500 esercenti; target 100k mancato di ~5× | DOCUMENTATO |
| 11 | giu 2017 | **Esselunga** (Segrate → Milano → 154 punti vendita) | Legittimazione istituzionale | Citata dal CEO come milestone fondativa | DOCUMENTATO |
| 12 | 2017+ | **Cashback finanziato dall'esercente** (Satispay Business) | Dare al negoziante uno strumento di marketing, non solo un metodo di pagamento | Categoria "Cashback" agli Smart Award 2020, dominata dalle farmacie | DOCUMENTATO |
| 13 | ~2018 | **Salvadanaio** (arrotondamento + risparmio) | Da app di pagamento a wallet | 500k utenti a nov 2018 | PROBABILE |
| 14 | 2019 | Bollettini, pagoPA, bollo auto, richiesta denaro, taxi | Occupare occasioni d'uso periodiche | 800k utenti, 90k esercenti | DOCUMENTATO |
| 15 | n.d. | **Referral "Invita un amico"**: bonus a entrambi **solo dopo il primo acquisto reale entro 30 giorni** | Viralità allineata all'attivazione, non all'iscrizione | Importi variabili, storico mai pubblicato | DOCUMENTATO (meccanica) / NON DOCUMENTATO (importi) |
| 16 | 2020 | **Smart Award**: classifiche pubbliche dei negozi più "smart", declinate per regione | PR locale gratuita + gamification esercenti | Decine di articoli su testate cittadine | DOCUMENTATO (esistenza) / INFERENZA (intento) |
| 17 | lug 2021 | Cashback **autofinanziato 20-30 M€** alla chiusura del Cashback di Stato | Trattenere gli utenti abituati agli incentivi | 1,8 M utenti, 160k esercenti | DOCUMENTATO |

**Serie storica:** 100 utenti (2015, primo pagamento in negozio) → 4.500 esercenti (giu 2016) → 12.500 esercenti / ~100k MAU (mar 2017) → 18.000 esercenti / 160k utenti (nov 2017) → 500k utenti (nov 2018) → 800k / 90k (set 2019) → 1M / 100k (mar 2020) → 3M / 200k (set 2022) → 5M / 380k (fine 2024).

## 4.2 Cosa NON è documentato (e quindi non copiare a scatola chiusa)

- **"Satispay Ambassador"**: nessuna fonte. Esistono un "Programma Partner" (rivenditori/integratori) e il "Community Bonus" (referral utenti). **Nessun programma ambassador.**
- **Rete di agenti esterni**: nessuna fonte. L'evidenza indica **dipendenti commerciali interni** (city manager), non una rete di incaricati.
- **Importi storici del referral 2015-2018**: mai pubblicati.
- **Tempi medi di onboarding esercente**: nessuna fonte.
- **Partnership Q8, Autostrade/Telepass, Banca 5/tabaccai**: nessuna fonte autorevole. La carburanti documentata è **Eni** (2023).
- **Campagne universitarie**: nessuna fonte.
- **Processi strutturati di raccolta feedback**: nessuna fonte.

*Fonti principali: Fondazione Bullone (intervista Dalmasso, 2021), Fintech Leaders podcast, ANSA 21/06/2016, ictBusiness 10/03/2017, ADC Group (campagna OOH 2016), Ravenna e Dintorni 18/07/2022, Quotidiano Piemontese 20/10/2020, satispay.com/support.satispay.com, Wikipedia IT, EconomyUp, Punto Informatico, MilanoToday. URL completi in appendice.*

---

# 5. Principi applicabili a KMoney

Non copiare Satispay: il modello è diverso (loro *riducono* il costo del pagamento per l'esercente; voi chiedete all'esercente di *accettare uno sconto* in cambio di clienti). Questi sono i principi che si trasferiscono davvero.

| # | Principio Satispay | Traduzione per KMoney | Perché regge anche nel vostro modello |
|---|---|---|---|
| **P1** | Densità geografica prima della scala | **Una microarea di Reggio, non "Reggio Calabria"**. Obiettivo: che un cliente KMoney trovi almeno 3 esercenti utili nel raggio di 400 m dove già passa ogni giorno | Il valore del wallet per il cliente è `f(esercenti raggiungibili nella routine)`, non `f(esercenti totali)` |
| **P2** | Errori piccoli in città piccole | Il primo mese è un **esperimento**, non un lancio. Cambi prezzo, % accettata, tagli card senza costo reputazionale | Tutti i parametri sono già editabili da admin (`/admin/ky-cards`, `/admin/mlm-impostazioni`, ky-percentage per azienda) `[CODICE]` |
| **P3** | Pricing che rende irrazionale il rifiuto | Per l'esercente il costo di entrare deve essere **zero**: nessun canone, nessun hardware, `accepted_ky_percentage` che sceglie lui (può partire dal **25%**, cioè 5% di sconto effettivo) | `/kit-merchant` genera già il QR in PDF; nessun POS necessario `[CODICE]` |
| **P4** | Visibilità in vetrina = acquisizione clienti gratuita | **Vetrofania KMoney obbligatoria** in ogni esercente attivato, con QR del negozio. È il tuo canale di acquisizione a costo marginale zero | `/kit-merchant/qr-pdf` esiste già `[CODICE]` |
| **P5** | Incentivo puntato sul **primo acquisto**, non sull'iscrizione | Il bonus segnalazione `amico` oggi si eroga **alla registrazione** `[CODICE: ReferralBonusService]`. **Va spostato al primo acquisto presso un esercente entro 30 giorni** | È il singolo cambiamento con il miglior rapporto impatto/costo su tutto il funnel (P0-4) |
| **P6** | L'esercente finanzia il proprio cashback | Il vantaggio del cliente è già **finanziato dall'esercente** (accetta Ky sotto pari). Rendetelo esplicito e vendibile: "decidi tu quanto sconto, in cambio decidi tu quanti clienti" | `cashback_rules` con targeting esiste, ma è finanziato dal **conto sistema** `[CODICE]` — va aggiunta la variante "a carico dell'esercente" |
| **P7** | Gamification pubblica degli esercenti (Smart Award) | **"Vetrina della settimana" / classifica pubblica** dei negozi KMoney più usati a Reggio, pubblicata su un canale locale | La directory pubblica `/aziende` con mappa esiste già `[CODICE]` |
| **P8** | Occasioni d'uso ricorrenti (bollettini, bollo, buoni pasto) | Priorità alle **categorie ad alta frequenza**: bar/colazione, panificio, ortofrutta, tabacchi/edicola, parrucchiere, benzina, farmacia/parafarmacia. Il gioiello e il mobilificio vengono dopo | Frequenza, non scontrino medio, è ciò che crea abitudine |
| **P9** | City manager con responsabilità su UNA città | Tu sei la city manager di Reggio. **Non aprire una seconda città** finché la prima non ha ≥30 esercenti attivi e ≥40% di clienti ricorrenti | Il fallimento tipico delle reti MLM territoriali è espandersi in larghezza prima di aver validato in profondità |
| **P10** | Un proof point istituzionale sblocca gli altri | Cerca **1 insegna-ancora** a Reggio (supermercato di quartiere, catena locale, farmacia storica, distributore) e usala come referenza in ogni pitch successivo | Costa più fatica di 5 esercenti piccoli e vale di più di 20 |

**Il principio che NON si trasferisce:** Satispay ha risolto il problema two-sided con un **canale bancario** (83.000 POS in un colpo). Voi non lo avete. Il vostro sostituto è la **rete di relazioni personali dell'agente in una microarea** — che scala molto peggio ma parte molto prima. Ciò significa: dimensionare le aspettative sul numero di esercenti *per agente*, non sul numero assoluto.

---

# 6. Architettura commerciale clienti-esercenti-agenti

## 6.1 Il flusso economico reale (come è oggi nel codice)

```
CLIENTE                    KMONEY (Cassa Circuito)              ESERCENTE
   |                                |                               |
   |--- 100 € (Stripe/bonifico) --->|                               |
   |<---------- 125 Ky -------------|  emette 125 Ky di passività   |
   |                                |                               |
   |--- spesa: quota_Ky in Ky ------------------------------------->|
   |    + resto in EUR off-circuit -------------------------------->|
   |                                |                               |
   |                                |<--- l'esercente spende i Ky --|
   |                                |     nel circuito, o compensa  |
   |                                |     (netting), o resta fermo  |
```

**Tre osservazioni che determinano tutto il resto:**

**(a) Il Ky non ha scadenza e non è segregato.** `[CODICE]` I 25 Ky promozionali diventano indistinguibili dai 100 acquistati nel momento in cui toccano il conto. Il circuito accumula una passività permanente e crescente. Questo è gestibile, ma va **misurato** (KPI "esposizione Ky", §16) e **regolato** (P0-1).

**(b) Non esiste un percorso di riconversione Ky→EUR per gli esercenti.** `[CODICE]` Le aziende hanno il netting (`/compensazione`) per compensare crediti incrociati, ma nessun payout in euro. Solo gli **agenti** hanno un percorso Ky→EUR (`MlmPayoutService`, con soglia `mlm_payout_threshold_eur_cents`). **Questa è la verità che va detta all'esercente al primo incontro**, non al terzo: i Ky si rispendono nel circuito, non si incassano in banca. Se l'esercente lo scopre dopo, lo perdi e ti brucia il passaparola in tutta la microarea.

**(c) La rete esercenti deve essere circolare, non a stella.** Se tutti gli esercenti sono venditori e nessuno è compratore, i Ky si accumulano e il circuito si blocca. **Criterio di selezione derivante:** ogni esercente deve avere almeno **una categoria di spesa** che può soddisfare *dentro* il circuito (fornitore, servizi, consulenza, carburante, ristorazione per il personale). Vale più questo di dieci esercenti "belli" che non comprano nulla.

## 6.2 Ruoli e cosa muove ciascuno

| Attore | Cosa dà | Cosa riceve | Cosa lo fa restare |
|---|---|---|---|
| **Cliente** | Denaro anticipato (float) + traffico agli esercenti | Sconto effettivo `quota_Ky × 20%` + offerte + scoperta locale | Trovare 3+ esercenti utili nella propria routine settimanale |
| **Esercente** | Sconto su una quota del prezzo (finanzia lui il vantaggio) | Clienti nuovi + visibilità in directory/mappa + potere d'acquisto in Ky da rispendere | Che i Ky incassati siano **spendibili** e che i clienti KMoney siano **incrementali**, non gli stessi di prima che ora pagano meno |
| **Agente** | Acquisizione, formazione, assistenza, presidio | Commissioni su Prov K + bonus di struttura + cassetto Ky | Che il reddito cresca con **clienti che spendono**, non con agenti reclutati |
| **KMoney** | Piattaforma, emissione Ky, compliance, marketing | Margine KNM (default 30% della ricarica) + fee transazione + piani azienda | Che la passività Ky sia coperta dal margine e che la rete resti circolare |

## 6.3 L'errore da non fare

Il modello ha **due motori di crescita** che possono entrare in conflitto:
- **Motore A — circuito**: più esercenti → più valore per i clienti → più ricariche → più margine.
- **Motore B — rete agenti**: più agenti → più clienti → più punti → più bonus.

Se il motore B corre più veloce di A, ottieni molti clienti con Ky che non riescono a spendere, esercenti che non ricevono traffico, e un piano compensi il cui costo cresce senza fatturato che lo copra. **Regola di governo che raccomando:** non attivare un nuovo agente in una microarea finché quella microarea non ha almeno **10 esercenti attivi**. È un vincolo commerciale, ma è anche la miglior difesa sostanziale sull'art. 5 L. 173/2005: la rete cresce dietro alla capacità di vendita, non davanti.

---

# 7. Proposta di valore per ogni categoria

## 7.1 CLIENTE — la matematica corretta del 25%

### Il calcolo

Ricarica `R` euro → ricevi `R × (1 + b)` Ky, con `b = 25%`.

**Sconto implicito sui Ky:** `1 − 1/(1+b) = 1 − 1/1,25 = 20%`

**Sconto effettivo sullo scontrino:**

```
sconto_effettivo = quota_Ky_accettata × [1 − 1/(1+b)]
                 = quota_Ky_accettata × 20%
```

| Quota accettata dall'esercente | Su 100 € di spesa paghi | Risparmio | Sconto reale |
|---|---|---|---|
| 100% Ky | 100 Ky (costati 80 €) | 20 € | **20%** |
| 75% Ky | 75 Ky (60 €) + 25 € | 15 € | **15%** |
| 50% Ky | 50 Ky (40 €) + 50 € | 10 € | **10%** |
| 25% Ky | 25 Ky (20 €) + 75 € | 5 € | **5%** |
| 0% Ky | 100 € | 0 € | **0%** |

> **Non si può dire "25% di sconto". Mai.** Il 25% è un *bonus sulla ricarica*, non uno sconto sull'acquisto. La differenza fra 25% e 20% non è un dettaglio semantico: è la differenza fra una promozione veritiera e una pratica commerciale ingannevole (art. 21 Cod. Cons.), e i clienti se ne accorgono al primo scontrino.

### La formulazione corretta (usabile ovunque)

> **"Ricarichi 100 €, ne hai 125 da spendere. Sul totale di uno scontrino il risparmio dipende da quanto quel negozio accetta in KMoney: fino al 20% se accetta tutto, il 5% se accetta un quarto. Su ogni negozio trovi scritta la percentuale, prima di entrare."**

### Cosa il cliente deve sapere PRIMA di ricaricare (checklist obbligatoria)

| Domanda | Risposta da dare oggi `[CODICE]` | Da definire |
|---|---|---|
| Quando ricevo il bonus? | Al completamento dell'acquisto della KY Card (accredito a pagamento confermato — Stripe immediato, bonifico dopo conferma admin) | — |
| Dove posso spendere? | Solo presso esercenti convenzionati, **nei limiti della % che ciascuno accetta**. Elenco e mappa su `/aziende` | — |
| Posso riavere i miei euro? | **No.** Non esiste rimborso Ky→EUR per i clienti `[CODICE]` | ⚠️ **Da decidere e scrivere nelle condizioni.** È la domanda che ti faranno per prima |
| I Ky scadono? | Oggi **no** `[CODICE]` | ⚠️ Se introdurrete una scadenza sui Ky promozionali (P0-1), va comunicata **prima** della prima ricarica, non dopo |
| Ci sono commissioni? | `transaction_fees` è configurabile per tipo di operazione `[CODICE]` — verificare i valori in produzione | ⚠️ Da pubblicare |
| Esclusioni? | Non c'è oggi un meccanismo di esclusione per categoria merceologica | ⚠️ Da valutare (es. tabacchi, gratta e vinci, farmaci con obbligo di ricetta) |
| Limite per scontrino? | La quota massima è quella del prodotto/azienda | — |

**Regola d'oro operativa:** finché queste caselle non sono tutte piene e pubblicate su `/legale/limiti`, **non promuovere pubblicamente il bonus del 25%**. Rispondere "verifico e ti faccio sapere" costa dieci minuti; una condizione scoperta dopo costa il cliente e i suoi contatti.

## 7.2 ESERCENTE

**La proposta in una frase:** *"Decidi tu quanto sconto fare — dal 5% al 20% — e in cambio entri in un circuito di clienti che devono spendere lì. Non paghi canone, non ti serve un POS nuovo, e i KMoney che incassi li rispendi tu dai fornitori del circuito."*

| Cosa gli dai | Verificato | Nota |
|---|---|---|
| Zero canone, zero hardware | ✅ | QR statico/dinamico + NFC + codice `[CODICE]` |
| Controllo totale dello sconto | ✅ | `accepted_ky_percentage` per azienda e `ky_percentage` per prodotto |
| Visibilità in directory + mappa | ✅ | `/aziende` con filtro per % accettata e per settore |
| Kit QR stampabile | ✅ | `/kit-merchant/qr-pdf` |
| Offerte a tempo | ✅ | `listing_offers` con scadenza automatica |
| Shop / vetrina prodotti | ✅ | `listings` con categorie e sottocategorie |
| Potere d'acquisto in Ky | ✅ | può comprare da altri esercenti del circuito |
| Compensazione crediti | ✅ | `/compensazione` (netting) |
| Report incassi | ✅ | `/estratto-conto`, resoconto mensile automatico |
| E-commerce | ✅ | plugin WooCommerce + modulo Magento 2 |
| **Riconversione Ky→EUR** | ❌ | **Non esiste. Va detto al primo incontro.** |

**Le tre obiezioni vere** (risposte pronte in §12):
1. *"E se incasso KMoney e non riesco a spenderli?"* — è l'obiezione fondata. Risposta onesta + limite di esposizione consigliato all'esercente.
2. *"Mi porti clienti nuovi o solo gli stessi che ora pagano meno?"* — cannibalizzazione. Si risponde con la regola dell'offerta dedicata.
3. *"Quanto mi costa davvero?"* — `quota × 20%` + eventuale fee. Numero secco, mai giri di parole.

## 7.3 AGENTE — i numeri veri, senza abbellimenti

Con i parametri attualmente nel codice (margine KNM 30%, base = ricarica una tantum) `[CODICE]`:

**Formula:** `commissione_diretta = ricarica × 30% × %_diretta(punti_attivi)`

| Scenario mensile | Ricariche | Prov K | Punti agente | % diretta | Commissione diretta |
|---|---|---|---|---|---|
| Avvio (5 clienti × 120 €) | 600 € | 180 € | ~10 | 5% | **9,00 €** |
| Consolidamento (20 × 120 €) | 2.400 € | 720 € | ~40 | 15% | **108,00 €** |
| Maturità (50 × 120 €) | 6.000 € | 1.800 € | ~100 | 25% | **450,00 €** |
| Maturità alta (100 × 150 €) | 15.000 € | 4.500 € | ~200 | 40% | **1.800,00 €** |

> **Da tenere presente in ogni conversazione con un candidato agente:** questi importi presuppongono che **ogni cliente ricarichi ogni mese**. La base commissionabile è la singola ricarica, pagata **una sola volta** `[CODICE]`. Un cliente che ricarica una volta e poi smette genera commissione una volta sola. Non esiste rendita.

**Reddito totale ≈** commissioni dirette + indirette (4/2/1/0,5/8% sui livelli della downline, con gating) + bonus di struttura (max 200 € per evento BasiQ, distribuiti sulla catena) + Extra Bonus alla prima promozione (Senior 300 € · Top 3.000 € · SuperVisor 5.000 € · Manager 20.000 €). **Bonus Diretti disattivati** `[CODICE]`.

**Cosa NON promettere mai:** un numero. Nemmeno "in media". Il piano compensi è pubblico e simulabile (`/admin/mlm-simulatore`): mostra il simulatore, non una cifra.
---

# 8. Funnel dettagliati

## 8.A Funnel CLIENTE

**Definizione di "Attivazione Qualificata" (AQ)** — l'unica cosa che conta e l'unico evento che va premiato:

> **AQ = KYC approvato + prima ricarica + primo acquisto presso un esercente diverso dall'agente che l'ha portato, entro 30 giorni dalla registrazione.**

Il vincolo "esercente ≠ agente" è deliberato: chiude il self-dealing e rende l'evento verificabile. Il vincolo dei 30 giorni è preso da Satispay (referral pagato solo dopo il primo acquisto reale entro 30 gg) `[FONTE]`.

| # | Fase | Obiettivo | Messaggio | Canale | Azione agente | Automazione app | KPI | Ostacolo principale | Risposta | Incentivo sostenibile |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | **Contatto** | Ottenere 60 secondi di attenzione | "C'è un circuito di negozi qui a [zona] dove spendi meno. Ti mostro in un minuto?" | Di persona in negozio, WhatsApp 1:1, passaparola | Pitch 30" + mostrare la mappa `/aziende` sul telefono | — | n° conversazioni/settimana | "L'ennesima app" | Aprire subito la mappa con i negozi che **quella persona** già frequenta | Nessuno |
| 2 | **Beneficio** | Far capire il vantaggio **reale** | "Da [Bar X] paghi 100 e ne spendi 125 su un quarto del conto: risparmi 5 €. Da [Y] che accetta tutto, risparmi 20 €" | Stesso | Mostrare 2 esercenti concreti + il badge % | Badge `%Kmoney` già in directory `[CODICE]` | % che passa a registrazione | Diffidenza sul "troppo bello" | Dire il numero **piccolo** per primo (5%), non il grande | Nessuno |
| 3 | **Registrazione** | Account creato | "Serve solo email e nome, 2 minuti" | App/web, link referral dell'agente | Registrazione **assistita**, mai lasciata a dopo | `referral_code` in link `[CODICE]` | registrazioni/settimana | Abbandono al form | Farla insieme, sul posto | Nessuno |
| 4 | **Profilo** | Dati completi | "Completiamo così puoi già vedere le offerte" | App | Guida onboarding | `EnsureOnboardingComplete` middleware `[CODICE]` | % completamento | Campi percepiti invasivi | Spiegare che servono per legge (antiriciclaggio) | Nessuno |
| 5 | **KYC** | Documento caricato | "Foto del documento, come in banca" | App | **Farla sul posto col telefono dell'agente se serve** | Upload → `kyc_documents` `[CODICE]` | KYC iniziati / completati | Foto illeggibili, rinvio | Farla subito; verificare la foto prima di uscire | Nessuno |
| 6 | **Approvazione** | Conto attivo | "Ti arriva la conferma entro 24h" | Email/push | Follow-up se >48h | `KycController::approve()` + bonus benvenuto | tempo medio approvazione, % approvati | Attesa che raffredda | **SLA 24h** e comunicarlo | `welcome_bonus_amount` configurabile `[CODICE]` |
| 7 | **Prima ricarica agganciata a una spesa già prevista** | Ricarica con destinazione | "Cosa devi comprare questa settimana? Facciamo la ricarica su quello" | Di persona | **Non chiedere mai una ricarica generica.** Ancorarla a un acquisto già in programma | KY Card via Stripe/bonifico `[CODICE]` | % con prima ricarica, importo medio | "Non voglio anticipare soldi" | Partire dal **taglio minimo**; è un acquisto, non un deposito | Nessuno — il bonus 25% è già l'incentivo |
| 8 | **Primo acquisto** | Il loop si chiude | "Andiamo insieme da [esercente], così lo provi" | Di persona | **Accompagnamento fisico al primo acquisto** | — | % con 1° acquisto entro 7 gg dalla ricarica | Non sa dove/come pagare | Accompagnarlo. È l'attività a più alto ROI di tutto il funnel | **Bonus referral spostato qui** (P0-4) |
| 9 | **Seconda transazione ≤30 gg** | Abitudine | "Questa settimana da [Z] c'è l'offerta" | Push + WhatsApp | Follow-up giorno 7 e giorno 21 | ⚠️ **Da costruire** (P1) | % con 2ª tx entro 30 gg | Dimenticanza | Notifica automatica + offerta a tempo su un esercente **diverso** | Offerta esercente (`listing_offers`) |
| 10 | **Uso ricorrente** | Cliente vivo | "Le offerte della settimana" | Push settimanale | Presidio leggero | ⚠️ Digest settimanale da costruire (P1) | tx/mese, esercenti distinti | Saldo che finisce e non si ricarica | Ricarica ancorata a spesa ricorrente | Missioni (P2) |
| 11 | **Referral** | Crescita organica | "Se conosci qualcuno a cui serve, ecco il tuo link" | App `/invita` | Chiedere **solo dopo il 2° acquisto** | `ReferralBonusService` `[CODICE]` | referral inviati/convertiti | Chiedere troppo presto | Regola: mai chiedere referral prima di 2 transazioni | 10 Ky, **da spostare al 1° acquisto dell'invitato** |

**Le due modifiche software che raddrizzano questo funnel** (dettaglio §20):
- **P0-4** — bonus `amico` erogato al **primo acquisto dell'invitato presso un esercente**, non alla sua registrazione. Oggi `[CODICE]` si paga alla registrazione: incentiva iscrizioni fantasma e, essendo addebitato all'agente di riferimento **anche in scoperto**, può prosciugare il conto dell'agente senza generare un euro di fatturato.
- **P1-2** — tracciamento esplicito degli eventi di funnel (`first_purchase_at`, `second_purchase_at`, `activated_qualified_at`) su `users`, oggi ricostruibili solo con query pesanti sui transfer.

## 8.B Funnel ESERCENTE

### Categorie prioritarie per la prima microarea

Ordinate per **frequenza d'uso** (criterio Satispay P8), non per scontrino:

| Priorità | Categoria | Perché | Quanti nella microarea |
|---|---|---|---|
| 1 | Bar / caffetteria / colazione | Frequenza quotidiana. È il "primo acquisto" ideale | 3-4 |
| 2 | Panificio / gastronomia / ortofrutta | Frequenza 2-3×/settimana | 3-4 |
| 3 | Ristorante / pizzeria / asporto | Scontrino medio + occasione sociale | 3-4 |
| 4 | Parrucchiere / estetista / barbiere | Ricorrenza mensile, alta fedeltà, margini che reggono lo sconto | 2-3 |
| 5 | Farmacia / parafarmacia / erboristeria | Traffico alto, credibilità istituzionale ⚠️ verificare esclusioni su farmaci con ricetta | 1-2 |
| 6 | Tabacchi / edicola / cartoleria | Traffico altissimo ⚠️ margini bassissimi sui monopoli: puntare su articoli laterali | 1-2 |
| 7 | Abbigliamento / calzature | Scontrino alto, bassa frequenza — serve per il "wow" | 2-3 |
| 8 | Officina / gommista / autolavaggio | Scontrino molto alto, ottimi per esaurire saldi | 1-2 |
| 9 | Palestra / centro sportivo | Abbonamento = ricorrenza automatica | 1-2 |
| 10 | Servizi professionali (consulenza, grafica, IT) | **Chiave per la circolarità**: sono chi *compra* dagli altri | 2-3 |

### Criteri di selezione (checklist da usare in visita)

**Requisiti minimi:**
- ☐ Attività regolare con P.IVA, KYC superabile
- ☐ Posizione dentro la microarea o al massimo 500 m dal suo baricentro
- ☐ Titolare decisore raggiungibile (no franchising con acquisti centralizzati)
- ☐ Accetta almeno il **25%** in Ky
- ☐ Ha smartphone e connessione in negozio
- ☐ **Ha almeno una categoria di spesa acquistabile dentro il circuito** ← il più trascurato e il più importante

**Segnali positivi:** già fa fidelity/sconti; lamenta le commissioni POS; è in una via di passaggio; il titolare è in negozio.
**Segnali di allarme:** vuole solo incassare e mai spendere; chiede subito la riconversione in euro; margine sotto il 20% (non regge nemmeno il 5% di sconto); personale ad alto turnover che non sarà formato.

### Le 12 fasi

| # | Fase | Cosa fai | Materiale | KPI | Ostacolo | Risposta |
|---|---|---|---|---|---|---|
| 1 | **Mappatura** | Cammina la microarea, censisci ogni attività su un foglio: nome, categoria, orario buono | Foglio censimento | n° attività mappate | Nessuno | — |
| 2 | **Primo contatto** | Entra nell'orario morto (bar 15-17, negozi 10-12). Mai al sabato | Pitch 60" (§12) | n° contatti/settimana | "Non ho tempo" | "Torno giovedì alle 15, 10 minuti" — e torna |
| 3 | **Qualifica** | Checklist sopra, in 5 domande | Checklist stampata | % qualificati | Dice sì a tutto per liberarsi | Chiedere subito la % accettata: chi non risponde non è convinto |
| 4 | **Demo** | 8 minuti sul telefono: mappa, scheda negozio, pagamento QR, estratto conto | Telefono + conto demo | % demo → adesione | Diffidenza tecnologica | Fare **tu** un pagamento reale da 1 € davanti a lui |
| 5 | **Proposta economica** | Numero secco: "al 25% ti costa 5 € ogni 100. Zero canone" | Foglio A4 con i 5 scenari | — | "Quanto ci guadagni tu?" | Rispondi con la verità: percentuale sulle ricariche dei clienti che porto |
| 6 | **Adesione + KYC** | Registrazione azienda, upload documenti, sul posto | — | tempo contatto→KYC | Documenti non a portata | Fissare un secondo passaggio con lista precisa |
| 7 | **Configurazione** | `accepted_ky_percentage`, settore, indirizzo (per la mappa), logo, banner | Backoffice | % profili completi | Profilo lasciato vuoto | Farlo tu con lui, non "poi lo fai" |
| 8 | **Catalogo/offerte** | Minimo **3 prodotti o servizi** caricati con `ky_percentage` | `/annunci`, `listing_offers` | n° offerte attive/esercente | "Non ho nulla da caricare" | Anche un servizio generico: "Buono spesa 20 €" |
| 9 | **Kit e vetrina** | Stampa `/kit-merchant/qr-pdf`, vetrofania in vetrina, QR alla cassa | Kit merchant | % esercenti con vetrofania | Vetrofania nel cassetto | **Attaccala tu**, prima di uscire |
| 10 | **Formazione staff** | 15 minuti con **chi sta in cassa**, non col titolare | Scheda A5 plastificata alla cassa | % staff formato | Il titolare dice "glielo spiego io" | Insistere: chi rifiuta il pagamento è il commesso, non il titolare |
| 11 | **Lancio congiunto** | Giorno concordato: 5-10 tuoi clienti fanno il primo acquisto lì. Foto, storia, post | Post social + WhatsApp | tx nei primi 7 gg | Lancio senza clienti = esercente deluso | **Non attivare un esercente se non hai ≥5 clienti pronti a usarlo** |
| 12 | **Retention** | Visita fisica **ogni 2 settimane** i primi 2 mesi. Porta il dato: quante transazioni, quanto incassato, quanto potrebbe spendere | Report esercente `[CODICE: merchant-report]` | % esercenti con ≥1 tx negli ultimi 7 gg | Silenzio dopo l'attivazione | Regola: nessun esercente resta più di 14 giorni senza una tua visita nel primo trimestre |

### Obiettivo territoriale — versione realistica

| Orizzonte | Tuo obiettivo `[TUA INFO]` | **Mia proposta** | Perché |
|---|---|---|---|
| Prima rete | 30-50 esercenti attivi, 10 categorie, 5-10 ancore, 200-300 utenti verificati | **Corretto come obiettivo a 9-12 mesi** con 2-3 agenti attivi | 30-50 esercenti *attivi* (non registrati) è già una densità da città media |
| **Primi 90 gg, 1 sola agente** | — | **12-15 esercenti attivi, 5-6 categorie, 1-2 ancore, 80-110 clienti verificati** | Un esercente ben attivato costa 4-6 ore fra contatto, demo, KYC, catalogo, kit, formazione staff e lancio. 15 esercenti = ~75 ore, oltre a tutto il lavoro sui clienti |

## 8.C Funnel AGENTE

### Perché il funnel selettivo è anche la difesa legale

Le 11 fasi che hai descritto `[TUA INFO]` non sono burocrazia: sono la **prova documentale** che l'ingresso in rete non è un atto di reclutamento a pagamento ma la selezione di un incaricato che conosce già il prodotto. In caso di contestazione ex art. 5 L. 173/2005, ogni passaggio tracciato conta.

| # | Fase | Criterio di passaggio | Traccia software |
|---|---|---|---|
| 1 | Conosce KMoney **come cliente** | Registrato da ≥14 giorni come cliente | `users.created_at`, `mlm_role='cliente'` `[CODICE]` |
| 2 | KYC completato | `kyc_status = approved` | ✅ |
| 3 | **≥2 utilizzi reali** presso esercenti | 2 transfer verso 2 esercenti distinti | ⚠️ da rendere verificabile (P1-3) |
| 4 | Presentazione (gruppo ≤8) | Presenza registrata | ⚠️ manuale |
| 5 | **Informativa completa** su costi, contratto, compensi | Consegna documentata del "Documento informativo precontrattuale" | ⚠️ da costruire (P1-5) |
| 6 | Colloquio di selezione | Scheda colloquio compilata | ⚠️ manuale |
| 7 | **≥48h di riflessione** | Timestamp informativa → firma ≥48h | ⚠️ **da imporre tecnicamente** (P0-5) |
| 8 | Firma accordo | Contratto agente + direttive, con OTP | ✅ `mlm_agent_contract_signatures` con snapshot firmatario e direttive `[CODICE]` |
| 9 | Formazione + certificazione | Quiz superato | ❌ assente (P1-6) |
| 10 | Primi clienti ed esercenti | — | ✅ |
| 11 | **Attivo solo dopo risultati reali** | Vedi definizione sotto | ⚠️ da formalizzare (P1-4) |

### Definizione di "Agente Attivo" — misurabile

Un agente è **Attivo** in un mese `M` se soddisfa **tutti** i criteri:

| Criterio | Soglia | Fonte dato |
|---|---|---|
| Formazione completata e certificazione superata | sì | da costruire |
| Clienti verificati (KYC approvato) portati, cumulati | **≥5** | `mlm_clients` + `kyc_status` |
| Di cui con **primo acquisto** presso un esercente | **≥3** | da costruire (P1-2) |
| Esercenti introdotti **e attivati** (≥1 transazione) | **≥1** | `company_reports` + transfer |
| Reclami o segnalazioni di comunicazione scorretta | **0** | `support_messages` + registro reclami (P1-8) |
| Contratto e direttive firmati e vigenti | sì | ✅ `[CODICE]` |

**Aggiungo un criterio di mantenimento** `[RACCOMANDAZIONE]`: negli ultimi 90 giorni almeno **1 nuovo cliente qualificato** o **1 esercente attivato**. Senza, l'agente passa a "Dormiente" — visibile in dashboard, senza sanzione automatica ma con affiancamento obbligatorio.

> **Attenzione a un dettaglio del codice.** Oggi il grado si perde automaticamente per scadenza punti (`MlmRankEngine::syncRank()` retrocede fino a `start`, senza periodo di grazia) `[CODICE]`. Uno stato "Attivo/Dormiente" **commerciale** e la **qualifica** sono due cose diverse e vanno tenute separate anche nella comunicazione, o l'agente vivrà ogni retrocessione come una sanzione arbitraria.

### Profilo del candidato ideale

**Cerca:** chi ha già una rete di relazioni commerciali locali (ex commerciale, promotore, assicurativo, chi ha avuto un negozio); chi è **già cliente soddisfatto**; chi ha tempo strutturato (15-20 h/settimana) e non "quando capita"; chi fa domande sui numeri.

**Evita:** chi chiede quanto si guadagna prima di chiedere cosa si vende; chi ha già fatto 3+ MLM; chi vuole "solo il codice" per condividerlo online; chi non è disposto a fare il primo acquisto insieme al cliente; chi ha bisogno di reddito immediato (i primi 60 giorni rendono poco — dirlo esplicitamente).

**Canali di recruiting:** (1) clienti soddisfatti — il migliore, di gran lunga; (2) titolari di esercenti convenzionati e loro collaboratori; (3) segnalazioni da agenti attivi; (4) presentazioni aperte mensili; (5) associazioni di categoria locali. **Non**: annunci "guadagna da casa", gruppi Telegram di MLM, DM a freddo.

---

# 9. Struttura trasparente del pacchetto da 480 €

## 9.1 Il problema, detto chiaramente

Non esiste una soglia in euro nella legge italiana `[FONTE Normattiva]`. Esistono tre test qualitativi, e i 480 € vanno superati tutti e tre:

| Norma | Testo (estratto) | Cosa significa per voi |
|---|---|---|
| **art. 4 c. 4 lett. b) L. 173/2005** | «non può essere stabilito alcun obbligo di acquisto … **di servizi** … non strettamente inerenti e necessari all'attività commerciale in questione, e comunque **non proporzionati al volume dell'attività svolta**» | Se i 480 € sono **obbligatori**, siete già fuori. Se sono facoltativi, resta il test di proporzionalità |
| **art. 6 lett. b)** | è elemento presuntivo di piramidalità «l'obbligo del soggetto reclutato di corrispondere, **all'atto del reclutamento e comunque quale condizione per la permanenza** nell'organizzazione … una somma di denaro … **di rilevante entità e in assenza di una reale controprestazione**» | Serve una **controprestazione reale, documentata e valorizzata voce per voce** |
| **art. 6 lett. c)** | idem per «materiali, beni o servizi, **ivi compresi materiali didattici e corsi di formazione**, non strettamente inerenti e necessari … e non proporzionati» | La formazione, da sola, è la componente **più a rischio**: è quella che AGCM guarda per prima |

**Il precedente da tenere sul tavolo:** AGCM PS11086 **Lyoness**, 19/12/2018, **3.200.000 €**. Contributo d'ingresso **2.400 €**, progressione nei livelli commissionali tramite reclutamento, cashback qualificato come «aspetto secondario» (≈1/6 dei ricavi del sistema) `[FONTE agcm.it]`. E **Cons. Stato VI, 13/01/2020 n. 321**: piramidale quando c'è «assoluta prevalenza dei proventi connessi al **reclutamento e all'autoconsumo** su quelli derivanti dalle vendite dirette» `[FONTE giustizia-amministrativa.it]`.

## 9.2 Struttura raccomandata

### I sei principi non negoziabili

1. **Facoltativo.** Nessun agente può essere obbligato ad acquistarlo per firmare, operare o incassare provvigioni. Deve esistere ed essere praticabile un **percorso a 0 €**.
2. **Nessuna provvigione alla upline** sulla vendita del pacchetto. Nessun punto, nessuna qualifica, nessun bonus derivante dal suo acquisto. *(Già coerente col codice: `MlmPointsService` assegna punti solo da `registration` e `deposit` di clienti — un pacchetto agente non genererebbe nulla, purché non venga modellato come KY Card `[CODICE]`.)*
3. **Listino analitico** con prezzo e durata di ogni componente, acquistabili anche singolarmente.
4. **Recesso 10 giorni lavorativi** senza motivazione, con rimborso entro 30 giorni dalla restituzione (art. 4 c. 3).
5. **Riacquisto ≥90%** del costo originario per i beni integri, in ogni ipotesi di cessazione (art. 4 c. 6).
6. **Pro-rata sui servizi a consumo**: se l'agente interrompe, i mesi non goduti si rimborsano.

### Listino proposto

| # | Componente | Natura | Valore | Durata | Obbligatorio? | Note compliance |
|---|---|---|---|---|---|---|
| 1 | Formazione iniziale certificata (12 h, aula/online, con test) | Servizio | 120 € | una tantum | ❌ Facoltativo | *Necessaria e inerente* solo se realmente erogata e documentata (registro presenze, esito test) |
| 2 | Academy: aggiornamenti + webinar mensili | Servizio | 96 € (8 €/mese) | 12 mesi | ❌ | Pro-rata al recesso |
| 3 | Licenza CRM agente + dashboard avanzata | Software | 108 € (9 €/mese) | 12 mesi | ❌ | ⚠️ **Da costruire prima di venderla** |
| 4 | Landing page personale + QR/link referral personalizzati | Software | 36 € (3 €/mese) | 12 mesi | ❌ | ⚠️ Da costruire |
| 5 | Kit dimostrativo fisico (espositori, brochure 100 pz, vetrofanie 20, biglietti 250, badge) | **Beni** | 90 € | — | ❌ | **Soggetto a riacquisto 90%** se integro — art. 4 c. 6 |
| 6 | Carta NFC KMoney personale | Bene | 15 € | — | ❌ | idem |
| 7 | Assistenza dedicata (canale prioritario, SLA 24h lavorative) | Servizio | 60 € (5 €/mese) | 12 mesi | ❌ | Pro-rata |
| 8 | 2 eventi formativi territoriali/anno | Servizio | 40 € | 12 mesi | ❌ | Se non erogati → rimborso |
| | **TOTALE** | | **565 €** | | | |
| | **Prezzo pacchetto completo** | | **480 €** | | | **Sconto 15% dichiarato in fattura** |

Lo sconto sul bundle rende il pacchetto **economicamente vantaggioso rispetto all'acquisto separato** — che è precisamente l'argomento che rende difendibile la "reale controprestazione".

### Percorso a 0 € (obbligatorio)

| Componente | Versione gratuita |
|---|---|
| Formazione | Corso base online gratuito (4 h) + test di certificazione |
| Materiali | PDF scaricabili e stampabili a proprie spese |
| Link/QR referral | ✅ già gratuito nel prodotto `[CODICE: /invita]` |
| Dashboard base | ✅ già gratuita |
| Assistenza | Canale standard |

> **Se un candidato non può o non vuole spendere 480 €, deve poter fare l'agente comunque.** Questa frase va scritta nel materiale informativo, detta in ogni presentazione, e resa vera nel prodotto. È la singola cosa che separa un pacchetto servizi da un contributo d'ingresso.

### Modalità di pagamento

| Opzione | Struttura | Valutazione |
|---|---|---|
| A — Unica soluzione | 480 € una tantum | ✅ La più semplice e la più difendibile |
| B — Rateale 12 mesi | 12 × 40 € = 480 € | ✅ Accettabile. **Zero interessi** (evita ogni profilo di credito al consumo). Sospendibile in ogni momento, con perdita solo dei servizi futuri |
| C — Rateale con addebito automatico su provvigioni | trattenuta dai compensi | ⚠️ **Sconsigliata.** Crea un vincolo economico permanente fra rete e pacchetto: è esattamente ciò che l'art. 6 lett. b) chiama «condizione per la permanenza» |
| D — **Pagamento in KMoney/Ky** | — | 🔴 **Non procedere senza parere scritto.** Vedi sotto |

### Il nodo del pagamento in Ky — perché lo sconsiglio

`[RACCOMANDAZIONE]` Se l'agente paga il pacchetto con i Ky che ha ricevuto come provvigione (il "cassetto kmoney", `MlmWalletService` `[CODICE]`), si crea un anello chiuso **KMoney → agente → KMoney**. Tre conseguenze:

1. **Sostanziale.** Rafforza esattamente la lettura AGCM che ha affondato Lyoness: i flussi economici del sistema provengono dagli affiliati, non dalle vendite a consumatori finali.
2. **Fiscale.** Una provvigione pagata in Ky è comunque una provvigione: rilevante ai fini dell'art. 25-bis c. 6 DPR 600/1973 (ritenuta a titolo d'imposta 23% su base 78% = **17,94% effettivo**) `[FONTE Normattiva]` e ai fini contributivi. Il codice **non modella alcuna ritenuta** `[CODICE: mlm_payouts]`.
3. **IVA.** La vendita del pacchetto è una prestazione di servizi imponibile IVA a prescindere dal mezzo di pagamento; il pagamento in buoni multiuso non cambia il momento impositivo (art. 6-quater DPR 633/72) `[FONTE]`.

**Se proprio si vuole aprire questa strada**, la forma meno esposta è: pagamento in euro con **sconto commerciale** documentato, oppure accettazione di Ky con **tetto** (es. max 50% dell'importo) e solo per Ky **acquistati**, mai per Ky ricevuti come provvigione. In ogni caso: **parere scritto prima**.

### Clausole contrattuali minime

- ☐ Elenco analitico servizi con valore, durata e modalità di erogazione
- ☐ Dichiarazione espressa: *«L'acquisto del presente pacchetto non è condizione per la nomina, la permanenza in rete, l'assegnazione di clienti, l'avanzamento di qualifica né la maturazione di provvigioni.»*
- ☐ Recesso 10 gg lavorativi, rimborso entro 30 gg dalla restituzione (art. 4 c. 3)
- ☐ Riacquisto beni integri ≥90% in ogni ipotesi di cessazione (art. 4 c. 6)
- ☐ Rimborso pro-rata servizi non goduti
- ☐ Interruzione attività: cosa accade a rate future (si estinguono), servizi (cessano), beni (riacquisto), provvigioni maturate (**restano dovute**)
- ☐ Nessuna provvigione alla upline sul pacchetto
- ☐ Foro, reclami, ADR
- ☐ Fattura elettronica per ogni pagamento, con dettaglio delle voci

---

# 10. Piano operativo di 90 giorni

## 10.1 Valutazione dei tuoi numeri

| Tuo target `[TUA INFO]` | Verdetto | Motivo |
|---|---|---|
| Gg 1-15: 5 esercenti pilota, 3 categorie, 20 offerte | ⚠️ **ottimistico** | Un esercente attivato bene = 4-6 h. Nei primi 15 gg vanno anche definiti microarea, materiali e verifica onboarding. **Proposta: 3-4 esercenti** |
| Gg 16-30: 50 conversazioni → 20 registrazioni → 15 KYC → 10 primi acquisti → 6 seconde tx | ⚠️ **conversioni troppo alte in coda** | 40% contatto→registrazione è plausibile su contatti caldi. 75% KYC è ok. 67% registrazione→acquisto è alto; **60% è più realistico**. Il vero rischio è che 10 primi acquisti su 3-4 esercenti li saturi |
| Gg 31-60: 10 esercenti, 50-60 utenti, 35 primi acquirenti, 20 ricorrenti | ✅ **coerente** | Il rapporto 20/35 di retention (57%) è ambizioso ma raggiungibile con presidio fisico |
| Gg 61-90: 12-15 esercenti, 90-100 utenti, 60 primi acquirenti, 35 con 2 acquisti, **3 agenti inseriti, 2 attivi** | 🔴 **incoerente col funnel agente** | Il funnel richiede: cliente da ≥14 gg → 2 utilizzi reali → presentazione → colloquio → 48h → firma → formazione → primi risultati. Un agente reclutato al giorno 61 **non può essere "attivo" entro il giorno 90**. E "attivo" richiede ≥5 clienti verificati e ≥1 esercente attivato |

**Correzione principale:** sposta l'obiettivo agenti da "3 inseriti, 2 attivi entro il 90" a **"3-4 candidati identificati e in valutazione, 1-2 contratti firmati entro il 90, primi agenti attivi nel mese 5"**. Non è un ridimensionamento dell'ambizione: è ciò che rende il funnel selettivo credibile invece che decorativo.

## 10.2 Tre scenari

| Metrica a 90 giorni | 🟡 Prudente | 🟢 **Realistico** | 🔴 Ambizioso |
|---|---|---|---|
| Esercenti contattati | 40 | **60** | 90 |
| Esercenti attivati (≥1 tx) | 7 | **12** | 18 |
| Categorie coperte | 4 | **6** | 8 |
| Esercenti "ancora" | 0 | **1** | 2 |
| Conversazioni cliente | 90 | **150** | 220 |
| Registrazioni | 35 | **60** | 95 |
| KYC approvati | 26 | **45** | 72 |
| Con prima ricarica | 20 | **36** | 60 |
| Con primo acquisto | 17 | **30** | 52 |
| Con 2ª tx entro 30 gg | 8 | **17** | 32 |
| **Attivazioni Qualificate** | **8** | **17** | **32** |
| Volume ricariche cumulato | 2.400 € | **5.400 €** | 10.800 € |
| Candidati agente in valutazione | 1 | **3** | 6 |
| Contratti agente firmati | 0 | **1** | 3 |
| Agenti attivi (def. §8.C) | 0 | **0** | 1 |

**Assunzioni dello scenario realistico** `[IPOTESI]`: 20 h/settimana di lavoro effettivo; ricarica media 150 €; conversione contatto→registrazione 40%; registrazione→KYC 75%; KYC→ricarica 80%; ricarica→acquisto 83%; acquisto→2ª tx 57%.

## 10.3 Tabella settimanale

**Legenda:** `E` = esercenti · `C` = clienti · `A` = agenti · `S` = sistema/materiali

| Sett. | Fase | Attività principali | Output verificabile |
|---|---|---|---|
| **1** | Setup | `S` Definire la microarea (criteri §10.4) e camminarla tutta · Censire ogni attività su foglio · `S` Verificare end-to-end l'onboarding con 2 account reali (registrazione→KYC→ricarica→acquisto) e annotare ogni attrito · `S` Verificare i tagli KY Card in produzione e il bonus effettivo per taglio | Microarea definita su mappa · Censimento (≥60 attività) · **Report attriti onboarding** · Tabella tagli card |
| **2** | Setup | `S` Materiali: A4 economico esercente, scheda cassa A5, vetrofania, brochure cliente · `S` Script §12 personalizzati · `S` Pubblicare condizioni corrette del 25% su `/legale/limiti` · `E` Primi 10 contatti a freddo | Kit materiali pronto · 10 contatti · 3 appuntamenti fissati |
| **3** | Pilota E | `E` 8 visite → 3 demo → **2 esercenti firmati e configurati** (profilo, %, catalogo ≥3 voci, kit, staff formato) · `C` Prime 15 conversazioni su contatti caldi | 2 esercenti live · 15 conversazioni |
| **4** | Pilota E | `E` 8 visite → **+2 esercenti** (categoria diversa) · `C` 15 conversazioni → 8 registrazioni assistite → 6 KYC | **4 esercenti live** in ≥3 categorie · 8 registrazioni |
| **5** | Attivazione C | `C` Accompagnare **8 primi acquisti** fisicamente · `E` +2 esercenti · `S` Primo digest offerte via WhatsApp | 8 primi acquisti · 6 esercenti |
| **6** | Attivazione C | `C` 20 conversazioni → 10 registrazioni · Follow-up giorno 7 sui primi acquirenti · `E` +2 esercenti · **Mini-evento: "Colazione KMoney"** in un bar convenzionato | 6 esercenti · 18 registrazioni cumulate · 1° evento |
| **7** | Densità | `E` +2 esercenti (puntare sulla **1ª ancora**) · `C` 20 conversazioni · **campagna 2ª transazione** su chi ha comprato ≥14 gg fa | 8-10 esercenti · prime 2ª tx |
| **8** | Densità | `E` +1-2 · `C` 20 conversazioni · `A` **Identificare i primi 2-3 candidati agente** fra i clienti più attivi (≥2 utilizzi reali) | 10 esercenti · 30 registrazioni cumulate · 3 candidati |
| **9** | Consolidamento | `E` Giro di **retention su tutti gli esercenti** con report dati alla mano · `C` 20 conversazioni · **2° evento**: presentazione clienti | Report per ogni esercente · 2° evento |
| **10** | Consolidamento | `E` +1-2 · `C` 20 conversazioni · `A` **1ª presentazione opportunità agente** (gruppo ≤8, materiale trasparente §12) | 12 esercenti · 1ª presentazione |
| **11** | Rete A | `A` Colloqui individuali coi candidati · consegna Documento Informativo · **avvio periodo di riflessione 48h** · `C` campagna riattivazione su chi non compra da 30 gg | Documenti consegnati e datati |
| **12** | Rete A | `A` **1ª firma contratto** + avvio formazione · `E` +1-2 · `C` 20 conversazioni | 1 contratto firmato |
| **13** | Bilancio | `S` **Revisione KPI a 90 giorni** su tutte le metriche §16 · Retrospettiva scritta: cosa ha funzionato, cosa no, cosa cambiare nei parametri (%, tagli, offerte) · Decidere se aprire una 2ª microarea | Documento di revisione + parametri aggiornati |

## 10.4 Come scegliere la microarea

`[RACCOMANDAZIONE]` — non invento dati su Reggio, ti do il metodo. Cammina 3 candidate e assegna un punteggio:

| Criterio | Peso | Come misurarlo |
|---|---|---|
| Densità commerciale | 25% | N° attività al dettaglio in 400 m di raggio. Contale a piedi |
| Mix di categorie ad alta frequenza | 20% | Presenza di bar, panificio, ortofrutta, farmacia, parrucchiere in 400 m |
| Popolazione residente/lavorativa | 20% | Ci sono uffici, scuole, un ospedale, un mercato? |
| **Tua rete personale preesistente** | 20% | Quante di quelle attività conosci già personalmente? ← il fattore più predittivo nei primi 90 giorni |
| Circolarità potenziale | 10% | Quante attività potrebbero comprare dalle altre? |
| Concorrenza di altri circuiti | 5% | Sono già in un circuito locale? |

**Dimensione target:** un perimetro percorribile a piedi in 15-20 minuti, ~300-500 m di raggio, 60-120 attività commerciali censite. Se la candidata ha meno di 40 attività, è troppo piccola; se richiede l'auto per spostarsi tra i punti, è troppo grande.

---

# 11. Routine giornaliera e settimanale

## 11.1 Settimana tipo (20 h)

| Giorno | Fascia | Blocco | Ore |
|---|---|---|---|
| **Lun** | 10-12 | 🏪 **Esercenti nuovi** — 4 visite a freddo (orario morto) | 2 |
| | 18-19 | 📱 Follow-up WhatsApp clienti giorno-7 | 1 |
| **Mar** | 15-17 | 🏪 **Esercenti** — demo e chiusure fissate lunedì | 2 |
| | 17-18 | 🛠 Onboarding esercente: catalogo, kit, formazione cassa | 1 |
| **Mer** | 10-13 | 👥 **Clienti** — conversazioni + registrazioni assistite + KYC sul posto | 3 |
| **Gio** | 15-17 | 👥 **Clienti** — accompagnamento **primi acquisti** (il blocco a più alto ROI) | 2 |
| | 18-19 | 🎓 Formazione personale / academy / aggiornamenti | 1 |
| **Ven** | 10-12 | 🏪 **Retention esercenti** — giro con i dati in mano | 2 |
| | 17-18 | 🤝 Colloqui candidati agente (solo su appuntamento) | 1 |
| **Sab** | 10-13 | 🎪 Presidio: evento, demo in negozio, presentazione | 3 |
| **Dom** | — | ❌ Nessuna attività commerciale | 0 |
| **Qualsiasi** | 30 min/g | 📊 **Rito quotidiano** (sotto) | ~2,5 |

## 11.2 Rito quotidiano (30 minuti, sempre)

| Min | Attività |
|---|---|
| 0-10 | **Dashboard**: nuove registrazioni ieri, KYC in attesa >24h, prime ricariche, primi acquisti, transazioni per esercente |
| 10-20 | **Follow-up mirato**: chi si è registrato ieri e non ha fatto KYC · chi ha ricaricato e non ha ancora speso · esercenti a zero transazioni da 7 gg |
| 20-25 | **Log** su un unico foglio: conversazioni fatte, esito, prossimo passo, data |
| 25-30 | **Pianificare il giorno dopo**: nomi, orari, obiettivo per ogni visita |

## 11.3 Rito settimanale (venerdì, 60 minuti)

1. Aggiornare la **scheda KPI** (§16) — 15 min
2. Confrontare con il target dello scenario realistico — 5 min
3. Identificare il **collo di bottiglia della settimana** (una sola fase) — 10 min
4. Decidere **una** contromisura per la settimana successiva — 10 min
5. Preparare il **digest offerte** da mandare ai clienti — 20 min

## 11.4 Rito mensile (2 ore)

- Report per **ogni esercente attivo**: transazioni, incassato, saldo Ky, cosa può comprare nel circuito
- Revisione parametri: tagli card, % accettate, offerte che hanno funzionato
- Segnalazione al team prodotto di attriti e bug riscontrati
- Verifica: nessun agente in rete ha fatto comunicazioni fuori dagli script
---

# 12. Script e messaggi

Tutti pronti da copiare. Ogni testo rispetta tre vincoli: **(a)** nessuna promessa di guadagno, **(b)** il vantaggio del cliente espresso come `quota × 20%` e mai come "25% di sconto", **(c)** identificazione come **Agente KMoney** al primo contatto.

## 12.1 Pitch cliente — 30 secondi

> «Ciao, sono [Nome], **Agente KMoney**. KMoney è un circuito di negozi qui in zona: ricarichi 100 € e ne hai 125 da spendere. Quanto risparmi davvero dipende da quanto quel negozio accetta in KMoney — al [Bar X], che accetta un quarto del conto, sono 5 € ogni 100; da [Y], che accetta tutto, sono 20 €. Ti faccio vedere la mappa dei negozi qui vicino?»

## 12.2 Pitch cliente — 2 minuti

> «Sono [Nome], **Agente KMoney**. Ti spiego in due minuti, poi mi dici se ti interessa.
>
> **Come funziona.** Ricarichi il tuo conto KMoney — per esempio 100 € — e ne ricevi 125 da spendere. Il 25% in più è un bonus sulla ricarica.
>
> **Quanto risparmi davvero.** Qui devo essere preciso, perché il 25% non è uno sconto del 25%. Ogni negozio decide quanta parte del conto accettare in KMoney: c'è chi accetta un quarto, chi metà, chi tutto. Su un negozio che accetta un quarto, su 100 € di spesa risparmi 5 €. Su uno che accetta tutto, risparmi 20 €. La percentuale è scritta sulla scheda di ogni negozio, prima che tu entri.
>
> **Dove si usa.** Solo nei negozi convenzionati. Ti apro la mappa: questi sono quelli entro dieci minuti da casa tua. [mostra] Al momento siamo [N] attività, ne aggiungiamo ogni settimana.
>
> **Le cose che devi sapere prima.** I KMoney si spendono nei negozi del circuito, non si riconvertono in euro sul tuo conto corrente. Serve un documento d'identità per la verifica, come in banca. [aggiungi qui, quando definite: scadenze, eventuali commissioni, esclusioni]
>
> **Cosa ci guadagno io.** Sono un agente: KMoney mi riconosce una percentuale sulle ricariche dei clienti che porto. Te lo dico perché è giusto tu lo sappia.
>
> Se ti va, ci mettiamo due minuti a registrarti adesso e ti accompagno io al primo acquisto.»

## 12.3 WhatsApp — invito cliente

> Ciao [Nome]! Sono [Tuo nome], sono diventata **Agente KMoney**: è un circuito di negozi qui a [zona] dove ricarichi 100 € e ne spendi 125.
>
> Il risparmio reale dipende dal negozio: dove accettano un quarto del conto sono 5 € su 100, dove accettano tutto sono 20 €. Nella zona ci sono già [N] attività — [Bar], [Panificio], [Parrucchiere].
>
> Se ti interessa te la faccio vedere in 5 minuti quando passi, senza impegno 🙂

## 12.4 WhatsApp — follow-up dopo la registrazione

> [Nome], registrazione fatta 👍
>
> Manca solo la verifica del documento — è obbligatoria per legge (antiriciclaggio), sono 2 minuti dall'app: Profilo → Documenti → foto fronte e retro.
>
> Se preferisci la facciamo insieme, dimmi quando passi.

## 12.5 WhatsApp — sollecito KYC

> Ciao [Nome], vedo che la verifica del documento è ancora in sospeso: senza quella il conto resta bloccato e non puoi usare il circuito.
>
> Serve solo una foto leggibile del documento, fronte e retro. Se la foto viene male me la mandi e controllo io prima che tu la carichi, così evitiamo di rifarla.

## 12.6 WhatsApp — primo acquisto

> [Nome], conto attivo ✅ Hai [X] KMoney disponibili.
>
> Cosa devi comprare questa settimana? Ti dico dove conviene usarli:
> • [Esercente A] — accetta il [%] → su [importo] risparmi [€]
> • [Esercente B] — accetta il [%] → su [importo] risparmi [€]
>
> Se vuoi ci vado con te la prima volta, così vedi come funziona alla cassa. Sono 5 minuti.

## 12.7 WhatsApp — seconda transazione

> [Nome], come ti sei trovata da [Esercente A]?
>
> Questa settimana da [Esercente B] c'è [offerta] fino a [data] — è in [via], a due passi. Ti lascio il link della scheda: [link]

## 12.8 WhatsApp — richiesta referral (mai prima del 2° acquisto)

> [Nome], visto che ormai lo usi: se conosci qualcuno della zona a cui può servire, questo è il tuo link personale → [link]
>
> Quando chi invitI fa il suo primo acquisto in un negozio del circuito, ricevi [X] KMoney. Nessuna fretta e nessun obbligo — te lo lascio solo perché ce l'hai.

*(Nota: questo testo presuppone la modifica P0-4. Finché il bonus si eroga alla registrazione, scrivi «quando chi inviti completa la registrazione» — ma è proprio la formulazione che genera iscrizioni inerti.)*

## 12.9 Pitch esercente — di persona

> «Buongiorno, sono [Nome], **Agente KMoney**. Le rubo tre minuti in un momento tranquillo — vengo per una cosa concreta, non per vendere un POS.
>
> KMoney è un circuito di clienti locali che ricaricano un conto e possono spenderlo solo nei negozi convenzionati. Il punto è questo: **decide lei quanta parte del conto accettare in KMoney.** Se accetta un quarto, sta facendo uno sconto effettivo del 5%; se accetta tutto, del 20%. Non un centesimo di più.
>
> Non c'è canone, non c'è attivazione, non serve un apparecchio nuovo: si paga con un QR che le stampo io.
>
> Cosa ci guadagna: entra nella mappa e nella vetrina del circuito, i clienti KMoney *devono* spendere in un negozio convenzionato, e i KMoney che incassa li rispende dai fornitori e dagli altri esercenti del circuito.
>
> **Una cosa gliela dico subito perché è la domanda giusta:** i KMoney non si riconvertono in euro sul conto corrente. Si rispendono dentro il circuito. Per questo prima di farle firmare qualsiasi cosa guardiamo insieme cosa *lei* comprerebbe dagli altri: se non c'è niente, glielo dico io che non le conviene.
>
> Le va se torno [giorno] e le faccio vedere come funziona in 8 minuti?»

## 12.10 WhatsApp — esercente

> Buongiorno [Nome], sono [Tuo nome], **Agente KMoney** — ci siamo visti [giorno] in negozio.
>
> Le lascio i due numeri che le interessano: **zero canone, zero attivazione**, e lo sconto lo decide lei — dal 5% (se accetta un quarto del conto in KMoney) al 20% (se accetta tutto).
>
> In zona siamo già [N] attività e [N] clienti attivi. Le mando la mappa: [link /aziende]
>
> Se le va le porto una demo di 8 minuti, mi dica lei giorno e ora.

## 12.11 Email — esercente

**Oggetto:** KMoney a [zona] — zero canone, lo sconto lo decide lei

> Gentile [Nome],
>
> sono [Tuo nome], **Agente KMoney** per la zona di [microarea]. Le scrivo dopo il nostro incontro di [giorno].
>
> **Cos'è.** Un circuito locale di clienti che ricaricano un conto KMoney e possono spenderlo solo presso le attività convenzionate.
>
> **Cosa le costa.** Nessun canone, nessun costo di attivazione, nessun hardware. L'unico costo è lo sconto che decide lei:
>
> | Quota che accetta in KMoney | Sconto effettivo sullo scontrino |
> |---|---|
> | 25% | 5% |
> | 50% | 10% |
> | 75% | 15% |
> | 100% | 20% |
>
> **Cosa ottiene.** Presenza nella directory e nella mappa pubblica del circuito; clienti che hanno già anticipato la spesa e devono usarla in un negozio convenzionato; possibilità di pubblicare offerte a tempo; potere d'acquisto in KMoney presso gli altri esercenti; report periodico degli incassi.
>
> **Cosa deve sapere prima di decidere.** I KMoney che incassa si rispendono all'interno del circuito e non sono riconvertibili in euro sul conto corrente. È la prima cosa da valutare: se fra le attività convenzionate non c'è nulla che lei acquisterebbe, glielo dico chiaramente — non le conviene.
>
> **Attivazione:** registrazione, verifica documenti (obbligatoria per legge), scelta della percentuale, caricamento di almeno tre prodotti o servizi, kit QR da esporre, 15 minuti di formazione a chi sta in cassa.
>
> Resto a disposizione per una dimostrazione di 8 minuti nel momento che preferisce.
>
> [Nome Cognome] — Agente KMoney
> [telefono] · [email]

## 12.12 Scaletta demo esercente (8 minuti)

| Min | Cosa | Come |
|---|---|---|
| 0-1 | **La mappa** | Apri `/aziende` sulla sua zona. «Questi sono i clienti che oggi cercano dove spendere» |
| 1-2 | **La sua futura scheda** | Mostra una scheda esistente: logo, %, prodotti, posizione |
| 2-4 | **Il pagamento** | Fai **un pagamento reale da 1 €** davanti a lui, col QR. Mostra la notifica di incasso |
| 4-5 | **L'estratto conto** | Mostra saldo, movimenti, report |
| 5-6 | **Cosa può comprare** | Filtra la directory su ciò che serve a lui. **Se qui non trovi nulla, dillo e fermati** |
| 6-7 | **I numeri** | Foglio A4 con la tabella delle 5 percentuali. Chiedi: «Quale sceglierebbe?» |
| 7-8 | **Prossimo passo** | «Se vuole partiamo: mi servono visura e documento. In 20 minuti è configurato» |

**Errori da non fare in demo:** parlare di MLM o di opportunità agente (mai al primo incontro); mostrare il backoffice admin; promettere un numero di clienti; dire "provi, tanto non le costa nulla" — le costa lo sconto, e minimizzarlo distrugge la fiducia quando se ne accorge.

## 12.13 Pitch candidato agente

> «[Nome], ti faccio una proposta e ti chiedo di **non** rispondermi oggi.
>
> Usi KMoney da [X] settimane e hai fatto [N] acquisti — quindi sai già cos'è, e questo è il motivo per cui te ne parlo: **non parlo con chi non l'ha usato**.
>
> Sto costruendo la rete di agenti su [zona]. Un agente fa tre cose: porta clienti, convenziona esercenti, e assiste entrambi. Viene pagato con una percentuale sulle ricariche dei clienti che porta e con bonus legati alla struttura.
>
> Tre cose che ti dico prima di qualunque altra:
> **Uno.** Non ti dico quanto guadagnerai, perché non lo so e chi te lo dice sta mentendo. Ti mostro il piano compensi per intero e facciamo insieme i conti sui **tuoi** numeri.
> **Due.** I primi due mesi rendono poco. Se ti serve reddito subito, questa non è la cosa giusta.
> **Tre.** C'è un pacchetto di strumenti e formazione da 480 €. **È facoltativo.** Puoi fare l'agente senza comprarlo, con la formazione base gratuita e i materiali in PDF. Se qualcuno ti dice che è obbligatorio, ti sta dicendo una cosa falsa.
>
> Se ti interessa capire meglio: c'è una presentazione [data], dopo ti do tutto il materiale per iscritto e **ti chiedo di prenderti almeno due giorni** prima di decidere qualsiasi cosa.»

## 12.14 Invito alla presentazione

> [Nome], come promesso: la presentazione su come funziona la rete agenti KMoney è **[data] alle [ora]** in [luogo]. Siamo in [N] persone, dura un'ora.
>
> Vediamo: il piano compensi per intero (numeri veri, non esempi gonfiati), cosa fa un agente in una settimana tipo, quanto costa e cosa è facoltativo, e come si viene selezionati.
>
> Non si firma niente quel giorno: dopo la presentazione ti do il materiale scritto e ti chiedo di prenderti almeno 48 ore.
>
> Confermi?

## 12.15 Presentazione trasparente dei 480 €

> «Parliamo dei costi, e voglio essere precisa perché è il punto dove le reti come questa fanno più danni.
>
> **Punto uno: non è obbligatorio.** Puoi firmare il contratto di agente, portare clienti, convenzionare esercenti e incassare ogni euro di provvigione **senza spendere un centesimo**. Formazione base online gratuita, materiali in PDF, link e QR referral inclusi, dashboard inclusa. Se qualcuno in questa rete ti dice il contrario, segnalamelo.
>
> **Punto due: cosa contiene.** [mostra il listino analitico] Sono otto voci con prezzo e durata: formazione certificata 120 €, academy 96 €, CRM 108 €, landing page 36 €, kit fisico 90 €, carta NFC 15 €, assistenza dedicata 60 €, eventi 40 €. Separatamente 565 €, insieme 480 €. Puoi comprarne anche solo una.
>
> **Punto tre: come si paga.** In un'unica soluzione, o 40 € al mese per 12 mesi senza interessi. Puoi interrompere quando vuoi: perdi i servizi futuri, non paghi le rate future.
>
> **Punto quattro: cosa succede se cambi idea.** Hai 10 giorni lavorativi dalla firma per recedere senza dover spiegare nulla, e ti rimborsiamo entro 30 giorni. Se smetti più avanti, i materiali fisici integri te li ricompriamo ad almeno il 90% di quanto li hai pagati, e i mesi di servizio non goduti ti vengono rimborsati.
>
> **Punto cinque, il più importante.** Nessuno in questa rete guadagna un centesimo dal fatto che tu compri il pacchetto. Non io, non chi sta sopra di me. Non genera punti, non fa avanzare nessuno di grado, non fa scattare nessun bonus. Se guadagnassimo sul pacchetto, avremmo interesse a vendertelo invece che a farti vendere — e questa sarebbe un'altra cosa, di cui anche la legge si occupa.»

## 12.16 Follow-up dopo la presentazione

> [Nome], grazie di essere venuta.
>
> Ti allego tutto per iscritto: piano compensi completo, bozza di contratto, listino analitico del pacchetto facoltativo, e il documento informativo con costi e condizioni di recesso.
>
> Come ti ho detto: **prenditi almeno due giorni.** Leggi con calma, fai leggere a qualcuno di cui ti fidi, e segnati le domande.
>
> Se decidi di no non cambia niente fra noi — resti cliente e continuo ad assisterti come sempre.
>
> Ci sentiamo [giorno]?

## 12.17 Obiezioni e risposte

### Clienti

| Obiezione | Risposta |
|---|---|
| «Quindi è un 25% di sconto su tutto?» | «No, e faccio bene a chiarirlo. Il 25% è un bonus **sulla ricarica**: 100 € diventano 125. Sullo scontrino il risparmio è la percentuale che quel negozio accetta, moltiplicata per 20%. Un quarto → 5%. Tutto → 20%.» |
| «Devo anticipare i soldi» | «Sì, è un acquisto anticipato, non un deposito. Per questo si parte dal taglio più piccolo e lo agganciamo a una spesa che hai già in programma questa settimana.» |
| «E se poi non li spendo?» | «Restano sul conto e non scadono [⚠️ aggiornare se introducete la scadenza sui Ky promozionali]. Ma la domanda giusta è un'altra: guardiamo insieme se nella tua zona c'è abbastanza da spendere. Se non c'è, aspettiamo.» |
| «Posso riavere i miei euro?» | «No. I KMoney si spendono nei negozi del circuito. Te lo dico prima e non dopo.» |
| «È una di quelle catene?» | «Capisco la domanda. La differenza è verificabile: qui non paghi nulla per entrare, non devi portare nessuno, e il vantaggio che ricevi è uno sconto reale in negozi che puoi visitare a piedi oggi. Se vuoi ti mostro la mappa e andiamo a vederne uno.» |
| «Ci sono commissioni?» | «[Rispondere con i valori reali di `transaction_fees` in produzione. Se non li conosci, dire: "Verifico e ti mando lo screenshot delle condizioni", e farlo.]» |

### Esercenti

| Obiezione | Risposta |
|---|---|
| «E se incasso KMoney e non riesco a spenderli?» | «È l'obiezione giusta e non la giro. Per questo prima di firmare guardiamo cosa comprerebbe lei nel circuito. E le consiglio io un limite: parta dal 25% — così l'esposizione massima è un quarto degli incassi da clienti KMoney, che nelle prime settimane sono pochi. Alzerà dopo, se le torna.» |
| «Mi porti clienti nuovi o gli stessi che ora pagano meno?» | «Rischio reale. Si evita così: lei fa l'offerta KMoney su un prodotto o una fascia oraria dove oggi ha poco traffico, non su quello che vende già bene. Le porto io i primi clienti il giorno del lancio e li conta.» |
| «Quanto mi costa davvero?» | «[quota]% del conto in KMoney significa uno sconto effettivo del [quota × 20]%. Su uno scontrino da 50 € al 25%, sono 2,50 €. Più le eventuali commissioni di transazione, che sono [valore reale].» |
| «Ho già un POS» | «Non lo sostituisce. È un canale in più con clienti che hanno già i soldi impegnati e possono usarli solo qui.» |
| «Il mio commercialista che dice?» | «Faccia bene a chiederglielo, e gli porti questo foglio con il funzionamento. Se ha domande le raccolgo e le giro a KMoney per iscritto.» |
| «Quanti clienti avete?» | «[Numero vero, sempre.] Oggi in questa zona siamo [N]. Se le sembrano pochi ha ragione — siamo partiti da [data]. Per questo il primo mese le porto io i clienti al lancio.» |

### Candidati agente

| Obiezione | Risposta |
|---|---|
| «Quanto si guadagna?» | «Non te lo dico, perché nessuno può saperlo. Ti mostro il piano per intero e facciamo i conti sui tuoi numeri: quanti clienti pensi di poter portare, quanto ricaricherebbero. Il risultato è il tuo, non una media.» |
| «Devo pagare per entrare?» | «No. Il contratto e l'attività non costano nulla. C'è un pacchetto di strumenti facoltativo da 480 €, e "facoltativo" significa che senza puoi fare tutto e incassare tutto.» |
| «È un MLM?» | «È una rete di vendita a più livelli, sì, e non ho motivo di nasconderlo. La differenza che conta è dove arrivano i soldi: qui si guadagna sulle ricariche e sugli acquisti dei clienti, non sull'ingresso di nuovi agenti. Ti mostro il piano e lo verifichi da sola.» |
| «Quanto ci metto a rientrare?» | «Non lo so e non te lo prometto. Ti dico invece cosa serve per i primi risultati: 5 clienti verificati, 3 che comprano, 1 esercente attivato. Quanto ci metti dipende da quante persone conosci nella tua zona.» |
| «Posso farlo part-time?» | «Sì, ma con orari fissi. 15-20 ore la settimana strutturate rendono molto più di 30 ore a singhiozzo, perché questo lavoro è fatto di ritorni: la seconda visita e il terzo follow-up sono dove si chiude.» |

## 12.18 🔴 Messaggi che gli agenti NON devono usare

**Divieto assoluto** — l'uso di una sola di queste formule è motivo di richiamo scritto e, se ripetuto, di sospensione (§15.6):

| ❌ Vietato | Perché | ✅ Al suo posto |
|---|---|---|
| "25% di sconto su tutto" | **Falso.** Lo sconto è `quota × 20%` | "Bonus del 25% sulla ricarica; sullo scontrino risparmi dal 5% al 20% a seconda del negozio" |
| "Guadagni garantiti" / "rendita" / "reddito passivo" | Promessa di risultato + la base commissionabile è una tantum | "I compensi dipendono dalle ricariche e dagli acquisti dei tuoi clienti" |
| "Puoi arrivare a X € al mese" | Promessa quantificata non verificabile | "Facciamo i conti insieme sui tuoi numeri con il simulatore" |
| "Investimento" (riferito ai 480 €) | Non è un investimento: è l'acquisto di servizi | "Pacchetto di strumenti e formazione, facoltativo" |
| "Solo per oggi" / "restano 2 posti" | Urgenza artificiale (art. 23 Cod. Cons.) | Nessuna scadenza inventata. Mai |
| "Devi ricaricare per mantenere il livello" | Autoconsumo obbligatorio → indice di piramidalità | Non esiste alcun obbligo di ricarica per l'agente |
| "Più agenti porti, più guadagni" | Incentivo al reclutamento come argomento primario | "Si guadagna sui clienti che spendono; la struttura è una conseguenza, non la fonte" |
| "Ci pensa KMoney a portarti i clienti" | Falso | "I clienti li porti tu; KMoney ti dà gli strumenti" |
| "È come Satispay" | Modelli diversi; appropriazione di reputazione altrui | "È un circuito locale di credito commerciale" |
| "Non è un MLM" | Falso | "È una rete a più livelli, e ti spiego come funziona" |
| "Non serve la partita IVA, tanto nessuno controlla" | Consiglio fiscale scorretto | "Sotto 5.000 € di reddito annuo l'attività è occasionale; sopra, cambia il regime. Chiedi al tuo commercialista" |
| "Con i KMoney ci compri anche il pacchetto agente" | Non deliberato, esposto legalmente e fiscalmente | Nulla, finché non c'è un parere scritto |
| Screenshot di guadagni altrui | Testimonianza di risultati economici — pratica ad alto rischio | Solo il simulatore ufficiale, sui numeri dell'interlocutore |

**Regola di identificazione, sempre:** ogni primo contatto — di persona, WhatsApp, email, social — deve contenere «sono [Nome], **Agente KMoney**». Nei post e nelle storie: `#AgenteKMoney` o la dicitura «Agente KMoney» in bio e nel testo. Non è cortesia: è l'art. 22 c. 2 Cod. Cons. sulla finalità commerciale, ed è la contestazione che è costata 160.000 € nel caso ARIIX/NewAge `[FONTE agcm.it]`.

---

# 13. Piano editoriale — 12 settimane

**Canali:** Instagram (feed + storie), Facebook (pagina + gruppi locali), WhatsApp Status e liste broadcast, Google Business Profile degli esercenti, volantino cartaceo in zona.

**Mix richiesto:** 40% offerte/prodotti · 25% tutorial · 20% esercenti/territorio · 10% testimonianze · 5% opportunità agente. **Su 36 contenuti (3/settimana):** 14 · 9 · 7 · 4 · 2.

| Sett | # | Canale | Formato | Titolo | Messaggio | CTA | Funnel | Metrica |
|---|---|---|---|---|---|---|---|---|
| 1 | 1 | IG+FB | Carosello 4 | **Cos'è KMoney in 4 slide** | Circuito locale: ricarichi 100, spendi 125; il risparmio dipende dal negozio | "Guarda la mappa" | Awareness | reach, click mappa |
| 1 | 2 | IG storie | Video 30" | **La matematica onesta del 25%** | Spiega la differenza fra bonus e sconto, con la tabella 5/10/15/20% | "Scrivimi" | Awareness | visualizzazioni, risposte |
| 1 | 3 | FB gruppi locali | Post testo | **Sto convenzionando i negozi di [zona]** | Presentazione personale come Agente KMoney | "Suggerisci un negozio" | Awareness | commenti |
| 2 | 4 | IG feed | Reel 20" | **[Esercente 1]: il primo** | Volto, prodotto, % accettata | "Vai a vedere" | Consideration | salvataggi |
| 2 | 5 | IG storie | Foto+testo | **Offerta della settimana** | Prodotto in offerta, prezzo pieno vs offerta | "Prenota" | Conversion | tx sull'offerta |
| 2 | 6 | IG | Carosello 3 | **Come ci si registra** | Screenshot dei 3 passaggi | "Registrati" | Consideration | registrazioni con UTM |
| 3 | 7 | IG feed | Reel | **[Esercente 2]** | idem | "Vai a vedere" | Consideration | salvataggi |
| 3 | 8 | IG storie | Video | **Come pagare con il QR** | 20 secondi, dal telefono alla cassa | "Provalo" | Activation | primi acquisti |
| 3 | 9 | WhatsApp | Broadcast | **Digest offerte** | Le 3 offerte della settimana | "Apri" | Conversion | tasso apertura |
| 4 | 10 | IG | Carosello | **Le 3 offerte della settimana** | — | "Vai" | Conversion | tx |
| 4 | 11 | IG storie | Q&A | **Le vostre domande** | Riprendi le obiezioni vere ricevute | "Chiedi" | Consideration | domande |
| 4 | 12 | FB | Post | **[Esercente 3] + micro-storia del titolare** | Territorio, non prodotto | "Passa a trovarlo" | Consideration | reach locale |
| 5 | 13 | IG | Reel | **Giro della zona in 60 secondi** | Tutti gli esercenti convenzionati, uno dopo l'altro | "Salva il post" | Awareness | salvataggi |
| 5 | 14 | IG storie | Foto | **Offerta lampo 48h** | — | "Corri" | Conversion | tx/48h |
| 5 | 15 | IG | Carosello | **Il KYC spiegato bene** | Perché serve, cosa serve, quanto dura | "Completalo" | Activation | KYC completati |
| 6 | 16 | IG feed | Foto | **Prima testimonianza cliente** | Persona reale, con consenso scritto. **Nessun importo** | "Provalo" | Consideration | commenti |
| 6 | 17 | IG storie | Video | **Colazione KMoney da [Bar]** | Evento dal vivo | "Vieni" | Activation | presenze |
| 6 | 18 | WhatsApp | Broadcast | **Digest** | — | — | Conversion | apertura |
| 7 | 19 | IG | Reel | **[Esercente 4-5]** | — | — | Consideration | — |
| 7 | 20 | IG | Carosello | **Come si legge la percentuale di un negozio** | Tutorial sul badge %Kmoney | "Guarda la mappa" | Consideration | click |
| 7 | 21 | IG storie | Sondaggio | **Che negozio manca in zona?** | Ricerca + engagement | "Vota" | Awareness | risposte → lista contatti esercenti |
| 8 | 22 | IG feed | Carosello | **Offerte** | — | — | Conversion | tx |
| 8 | 23 | IG | Reel | **Un giorno da Agente KMoney** | Dietro le quinte, onesto | "Scrivimi" | **Agente** | DM |
| 8 | 24 | FB | Post | **[Esercente 6] — l'ancora** | Il proof point | "Passa" | Consideration | reach |
| 9 | 25 | IG | Carosello | **Offerte + nuovo esercente** | — | — | Conversion | — |
| 9 | 26 | IG storie | Video | **Errore comune: "è uno sconto del 25%"** | Contenuto anti-fraintendimento. Rafforza la fiducia | "Chiedi" | Consideration | risposte |
| 9 | 27 | IG feed | Foto | **2ª testimonianza — esercente** | Titolare che racconta com'è andata | "Convenzionati" | **Esercente** | contatti |
| 10 | 28 | IG | Carosello | **Offerte** | — | — | Conversion | tx |
| 10 | 29 | IG | Reel | **La mappa oggi vs 2 mesi fa** | Crescita visiva | "Guarda" | Awareness | reach |
| 10 | 30 | IG storie | Testo | **Presentazione agenti [data]** | Invito trasparente, senza urgenza | "Scrivimi" | **Agente** | iscritti |
| 11 | 31 | IG | Carosello | **Offerte** | — | — | Conversion | — |
| 11 | 32 | IG | Reel | **Tutorial: invita un amico** | Come funziona e quando scatta il bonus | "Invita" | Referral | referral |
| 11 | 33 | FB | Post | **3ª testimonianza cliente** | — | — | Consideration | — |
| 12 | 34 | IG | Carosello | **Offerte di fine trimestre** | — | — | Conversion | tx |
| 12 | 35 | IG | Reel | **Bilancio dei primi 3 mesi** | Numeri veri: esercenti, clienti, transazioni. **Nessun dato economico degli agenti** | "Entra nel circuito" | Awareness | reach |
| 12 | 36 | IG storie | Video | **4ª testimonianza** | — | — | Consideration | — |

**Regole di produzione:**
- Ogni testimonianza richiede **consenso scritto** (GDPR) e non deve contenere **importi guadagnati**.
- Ogni post che parla di risparmio deve contenere la formula corretta o rimandare a dove è spiegata.
- Ogni contenuto di reclutamento (2 su 36) deve essere identificato come tale e non deve contenere cifre.
- Riusa: 1 Reel esercente → 1 storia + 1 post FB + 1 messaggio broadcast. **1 girato = 4 contenuti.**

---

# 14. Piano eventi e campagne

## 14.1 Eventi

| Evento | Cadenza | Formato | Obiettivo | KPI | Costo indicativo |
|---|---|---|---|---|---|
| **Demo in negozio** | 2/mese | 2 h in un esercente, tavolino, QR grande, registrazioni assistite | Attivazione + traffico all'esercente | registrazioni, primi acquisti | 0-30 € (offerti dall'esercente) |
| **Colazione KMoney** | 1/mese | 8-10 clienti in un bar convenzionato, 45 min, colazione pagata in Ky | Primo acquisto collettivo + prova sociale | primi acquisti, foto/contenuti | 30-50 € |
| **Presentazione clienti** | 1/mese | 45 min in sala o negozio, max 15 persone | Registrazioni + KYC sul posto | KYC completati | 0-50 € |
| **Colazione esercenti** | 1/trimestre | 6-8 titolari, 1 h, presentazione + confronto + **raccolta feedback strutturata** | Adesioni + circolarità (chi compra da chi) | adesioni, accordi incrociati | 60-100 € |
| **Giornata KMoney** | 1/trimestre | Tutti gli esercenti fanno un'offerta lo stesso giorno, comunicazione coordinata | Picco di transazioni + visibilità | tx nella giornata vs media | 100-150 € |
| **Presentazione agenti** | 1/mese, solo se ≥3 candidati | 1 h, max 8 persone, materiale scritto consegnato | Selezione | candidati → colloqui | 0-30 € |
| **Vetrina della settimana** | settimanale | Post + storia sull'esercente con più transazioni | Gamification esercenti (modello Smart Award) | tx dell'esercente premiato | 0 € |

## 14.2 Campagne

| Campagna | Trigger | Meccanica | Chi la finanzia | Durata | KPI |
|---|---|---|---|---|---|
| **Primo acquisto** | Cliente con ricarica e 0 acquisti da 7 gg | Push + WhatsApp con 2 esercenti nel raggio di 10 min | agente (tempo) | continua | % 1° acquisto entro 7 gg dalla ricarica |
| **Seconda transazione** | Cliente con 1 acquisto, 14 gg fa | Offerta a tempo su un esercente **diverso** dal primo | esercente | continua | % 2ª tx entro 30 gg |
| **Riattivazione 30** | 0 transazioni negli ultimi 30 gg | "Hai [X] KMoney fermi. Questa settimana da [Y] c'è [offerta]" | esercente | continua | % riattivati |
| **Riattivazione 60** | 0 transazioni negli ultimi 60 gg | Telefonata dell'agente, non messaggio | agente | continua | % riattivati |
| **Referral post-2° acquisto** | Cliente con ≥2 acquisti | Richiesta referral personalizzata | circuito (bonus Ky) | continua | referral/cliente attivo |
| **Missioni settimanali** ⚠️ | — | "Compra in 2 negozi diversi questa settimana" | circuito | da costruire (P2) | partecipazione |
| **Nuovo esercente** | Ogni attivazione | Annuncio + offerta di lancio + 5 clienti accompagnati il giorno 1 | esercente | 7 gg | tx nei primi 7 gg |
| **Esercente dormiente** | 0 tx da 14 gg | Visita fisica dell'agente col report | agente | continua | % esercenti risvegliati |

## 14.3 Iniziative referral — disegno corretto

`[RACCOMANDAZIONE]` Il referral va ridisegnato attorno a un principio unico: **si paga sul comportamento, non sull'iscrizione.**

| Livello | Trigger attuale `[CODICE]` | **Trigger proposto** | Importo |
|---|---|---|---|
| `amico` | registrazione dell'invitato | **primo acquisto dell'invitato presso un esercente, entro 30 gg** | 10 Ky (invariato) |
| `agente` | firma contratto agente | invariato, **ma solo se l'invitato completa la formazione** | 50 Ky |
| `attivita` | KYC azienda approvato | **prima transazione dell'azienda come esercente** | 100 Ky |

Motivo: nel modello attuale il bonus `amico` è addebitato al conto dell'agente di riferimento **anche in scoperto oltre il fido** `[CODICE: ReferralBonusService::fundingAccountFor()]`. Un cliente che invita 20 amici che si registrano e spariscono costa all'agente 200 Ky e produce zero. Con il trigger sull'acquisto, ogni euro di bonus corrisponde a fatturato reale — ed è anche l'unica versione difendibile sul piano dell'art. 5.

**Antifrode minimo da introdurre insieme** (P1-7): blocco self-referral (stesso documento, stesso IBAN, stesso dispositivo); tetto di N referral premiati per segnalante per mese; revisione manuale sopra soglia; blocco degli anelli (A→B→A).

---

# 15. Academy e onboarding agenti

## 15.1 Percorso in 6 moduli (12 ore)

| # | Modulo | Durata | Contenuti | Verifica |
|---|---|---|---|---|
| 1 | **Il prodotto** | 2 h | Wallet, Ky, KY Card e tagli, la matematica del 25%, % per azienda e per prodotto, KYC, carte NFC, modalità di incasso | Quiz 10 domande — **il calcolo del risparmio effettivo è eliminatorio** |
| 2 | **Cliente** | 2 h | Funnel, pitch, registrazione assistita, KYC sul posto, accompagnamento al primo acquisto, follow-up | Role-play registrato |
| 3 | **Esercente** | 2,5 h | Qualifica, demo, proposta economica, configurazione, catalogo, kit, formazione della cassa, retention | Role-play + configurazione di un esercente demo |
| 4 | **Piano compensi** | 2 h | Punti, qualifiche, retrocessione, commissioni dirette/indirette, gating, bonus struttura, cassetto Ky, payout | Quiz 15 domande + esercizio col simulatore |
| 5 | **🔴 Compliance e comunicazione** | 2,5 h | L. 173/2005 (artt. 4-7), Codice del Consumo, **lista dei messaggi vietati**, identificazione come agente, GDPR e consensi, privacy dei dati cliente, cosa NON dire mai | **Quiz eliminatorio: 100% di risposte esatte richiesto** |
| 6 | **Fiscale e amministrativo** | 1 h | Incaricato occasionale vs abituale, soglia 5.000 €, ritenuta 23% su base 78%, quando serve la P.IVA, fatturazione, tesserino di riconoscimento | Quiz 8 domande |

## 15.2 Certificazione

- Superamento di tutti i quiz, con il **modulo 5 al 100%**
- 2 role-play valutati (cliente + esercente)
- Firma del **Codice di condotta commerciale**
- **Validità 12 mesi**, poi aggiornamento obbligatorio di 2 ore (o rinnovo del quiz 5)
- Senza certificazione: l'agente può firmare il contratto ma **non può presentarsi come agente al pubblico** né ricevere assegnazione di clienti

## 15.3 Affiancamento — primi 30 giorni

| Giorno | Attività | Con chi |
|---|---|---|
| 1-3 | Formazione moduli 1-3 | Academy |
| 4 | **Affiancamento in campo**: 4 visite esercenti, l'agente guarda | Sponsor |
| 5 | 4 visite, l'agente parla, lo sponsor corregge dopo | Sponsor |
| 6-7 | Moduli 4-6 + certificazione | Academy |
| 8-14 | Prime visite in autonomia, debriefing telefonico serale ogni giorno | Sponsor |
| 15 | **Check-point 1**: ≥15 conversazioni, ≥5 registrazioni, ≥3 visite esercenti | Sponsor |
| 16-29 | Autonomia con check settimanale | Sponsor |
| 30 | **Check-point 2**: ≥5 clienti verificati, ≥2 primi acquisti, ≥1 esercente in pipeline avanzata | Sponsor + responsabile rete |

## 15.4 Piano dei primi 30 giorni dell'agente (target)

| Metrica | Target 30 gg |
|---|---|
| Conversazioni cliente | 40 |
| Registrazioni | 15 |
| KYC approvati | 10 |
| Clienti con primo acquisto | 5 |
| Esercenti contattati | 15 |
| Esercenti in demo | 5 |
| Esercenti attivati | 1 |
| Contenuti pubblicati | 8 |

## 15.5 Controllo qualità

| Controllo | Frequenza | Chi |
|---|---|---|
| **Mystery shopping**: qualcuno finge di essere un contatto e verifica il pitch | 1/trimestre per agente | Responsabile rete |
| Revisione dei contenuti social pubblicati | mensile | Responsabile rete |
| Verifica reclami e segnalazioni | continua | `support_messages` + registro dedicato (P1-8) |
| Audit dei referral (self-referral, cluster) | mensile | Admin, su `audit_logs` |
| Colloquio con 3 clienti a campione per agente | trimestrale | Responsabile rete |

## 15.6 Criteri di sospensione

**Sospensione immediata** (con blocco della liquidazione dei compensi in corso di accertamento):
- Promesse di guadagno quantificate o garantite
- Affermazione che il pacchetto 480 € è obbligatorio
- "25% di sconto" usato in comunicazione pubblica dopo un richiamo scritto
- Raccolta di denaro dai clienti al di fuori dei canali ufficiali
- Registrazioni fittizie o self-referral
- Mancata identificazione come agente in comunicazione commerciale, dopo un richiamo

**Richiamo scritto → sospensione alla seconda:** ritardo sistematico nell'assistenza; profili esercente lasciati incompleti; mancato uso degli script ufficiali; formazione scaduta.

**Procedura garantita:** contestazione scritta → 10 giorni per controdedurre → decisione motivata → possibilità di reintegro dopo formazione. Le provvigioni **già maturate** su affari regolarmente eseguiti restano dovute (art. 4 c. 9 L. 173/2005) — trattenerle sarebbe illegittimo.

## 15.7 Duplicazione etica

Il sistema è "duplicabile" solo se ciò che si duplica è **verificabile**:

1. **Playbook scritto** e versionato (questo documento è la v1)
2. **Nessuna informazione riservata allo sponsor**: il piano compensi è pubblico e simulabile
3. **Il nuovo agente deve poter fare tutto senza comprare nulla**
4. **Metriche pubbliche di rete**: numero di agenti attivi/dormienti, tasso di sopravvivenza a 6 mesi. Pubblicarle è la miglior difesa preventiva
5. **Nessun premio allo sponsor per il solo ingresso**: i bonus di struttura scattano su BasiQ, che con il gate P0-2 richiederà fatturato reale
6. **Limite di larghezza**: raccomando max **5 agenti diretti** per sponsor nei primi 12 mesi. Chi ne segue di più non li segue
---

# 16. KPI e dashboard

## 16.1 La metrica principale — valutazione della tua proposta

**La tua proposta** `[TUA INFO]`: *"Clienti con almeno 4 transazioni reali al mese presso almeno 2 esercenti differenti."*

**Verdetto: metrica eccellente, sbagliata per i primi 90 giorni.**

È eccellente perché cattura esattamente ciò che rende vivo un circuito: frequenza + varietà. Ed è allineata al benchmark: Satispay a Ravenna arriva a 11 tx/mese per utente `[FONTE]`, quindi 4 è una soglia di salute realistica **a regime**.

È sbagliata adesso per due ragioni concrete: (a) con 4-12 esercenti attivi, "2 esercenti differenti" è un vincolo che dipende dalla tua rete, non dal comportamento del cliente; (b) una metrica che resta a zero per 60 giorni non guida nessuna decisione, e verrà ignorata.

**Proposta: una metrica principale che evolve per fase.**

| Fase | Metrica principale | Definizione | Target |
|---|---|---|---|
| **F1 — Attivazione** (gg 0-90) | **AQ — Attivazioni Qualificate** | KYC approvato + prima ricarica + primo acquisto presso un esercente ≠ agente, entro 30 gg dalla registrazione | 17 a 90 gg |
| **F2 — Abitudine** (gg 90-180) | **CR30 — Clienti Ricorrenti** | ≥2 transazioni negli ultimi 30 gg presso ≥2 esercenti distinti | ≥40% degli AQ |
| **F3 — Densità** (180 gg+) | **La tua metrica**: ≥4 tx/mese presso ≥2 esercenti | — | ≥25% della base |

**Metriche complementari che raccomando di affiancare fin da subito:**

| Metrica | Definizione | Perché è importante |
|---|---|---|
| 🔑 **Merchant Liveness** | % di esercenti convenzionati con ≥1 transazione negli ultimi 7 giorni | **La metrica più predittiva di un two-sided market.** Un esercente a zero da 14 giorni è un esercente che sta per uscire. Target: ≥70% |
| 🔑 **Indice di circolarità** | % di Ky incassati dagli esercenti che vengono **rispesi** nel circuito entro 60 giorni | Misura se il circuito respira o si sta ingolfando. Target: ≥50% |
| **Densità percepita** | n° medio di esercenti attivi entro 500 m dalla residenza dei clienti | Traduce "quanti esercenti" in "quanto è utile per me". Target: ≥3 |
| **Esposizione Ky netta** | Ky emessi − Ky spesi presso esercenti | Passività effettiva del circuito |
| **Copertura del bonus** | (Ky promozionali emessi × costo) / margine incassato | Sostenibilità del 25% (§17) |

## 16.2 Dashboard completa

### A — Funnel cliente

| # | Metrica | Fonte dato | Stato |
|---|---|---|---|
| A1 | Contatti/conversazioni | manuale (CRM leggero, P1) | ❌ da costruire |
| A2 | Registrazioni | `users.created_at` | ✅ |
| A3 | KYC iniziati | `kyc_documents` primo upload | ✅ |
| A4 | KYC completati | `companies.kyc_status='approved'` | ✅ |
| A5 | Utenti approvati/attivi | `users.is_active` + conto attivo | ✅ |
| A6 | Prima ricarica | `ky_card_purchases` status completed | ✅ |
| A7 | **Primo acquisto** | primo transfer verso esercente | ⚠️ query pesante — serve `first_purchase_at` (P1-2) |
| A8 | **Seconda transazione ≤30 gg** | idem | ⚠️ idem |
| A9 | Attivi a 30/60/90 gg | coorte per mese di registrazione | ⚠️ da costruire |
| A10 | Frequenza mensile | tx/cliente/mese | ⚠️ |
| A11 | Valore medio transazione | AVG su transfer | ✅ |
| A12 | Esercenti distinti per cliente | COUNT DISTINCT | ⚠️ |

### B — Economia dei Ky

| # | Metrica | Fonte | Stato |
|---|---|---|---|
| B1 | Ky emessi totali | `ledger_entries` da Cassa Circuito | ✅ |
| B2 | **Ky promozionali emessi (il "25%")** | ⚠️ **non separabile oggi**: `ky_card_purchases.ky_amount` è il totale | 🔴 richiede P0-1 |
| B3 | Ky spesi presso esercenti | transfer verso conti azienda | ✅ |
| B4 | Ky fermi (giacenza media) | saldi conti privati | ✅ |
| B5 | Ky scaduti | — | 🔴 non esiste scadenza |
| B6 | Esposizione netta | B1 − B3 | ✅ calcolabile |
| B7 | Indice di circolarità | Ky rispesi dagli esercenti / Ky incassati | ⚠️ |

### C — Esercenti

| # | Metrica | Stato |
|---|---|---|
| C1 | Contattati (manuale) | ❌ |
| C2 | Registrati | ✅ `companies` |
| C3 | KYC approvati | ✅ |
| C4 | Con catalogo ≥3 voci | ✅ `listings` |
| C5 | **Attivi (≥1 tx negli ultimi 7 gg)** 🔑 | ✅ calcolabile |
| C6 | Transazioni/esercente/mese | ✅ |
| C7 | Incassato Ky/esercente | ✅ |
| C8 | **Ky rispesi/esercente** | ✅ |
| C9 | Dormienti (0 tx da 14 gg) | ✅ |
| C10 | Churn (usciti/sospesi) | ✅ `suspended_at` |

### D — Agenti

| # | Metrica | Stato |
|---|---|---|
| D1 | Candidati identificati | ❌ manuale |
| D2 | Presentazioni / colloqui | ❌ manuale |
| D3 | Contratti firmati | ✅ `mlm_agent_contract_signatures` |
| D4 | Formazione completata | ❌ da costruire |
| D5 | **Agenti attivi** (def. §8.C) | ⚠️ P1-4 |
| D6 | Agenti dormienti | ⚠️ |
| D7 | Sopravvivenza a 6 mesi | ⚠️ |
| D8 | Clienti/agente, esercenti/agente | ✅ |
| D9 | Compensi maturati/liquidati | ✅ `mlm_payouts` |
| D10 | **Rapporto compensi / margine generato** 🔑 | ⚠️ calcolabile ma non esposto |

### E — Qualità e rischio

| # | Metrica | Stato |
|---|---|---|
| E1 | Reclami aperti/chiusi/tempo medio | ⚠️ solo `support_messages` |
| E2 | Anomalie referral (self, cluster, anelli) | ❌ P1-7 |
| E3 | Segnalazioni comunicazione scorretta | ❌ P1-8 |
| E4 | Recessi entro 10 gg (agenti) | ❌ |
| E5 | Richieste di rimborso | ❌ |
| E6 | Integrità contabile | ✅ `accounting:verify-integrity` + `/health` |

### F — Economia

| # | Metrica | Stato |
|---|---|---|
| F1 | Volume ricariche | ✅ |
| F2 | Margine KNM (Prov K) | ✅ |
| F3 | Fee transazione incassate | ✅ `transaction_fees` |
| F4 | Ricavi piani azienda | ✅ `plan_payments` |
| F5 | Costo commissioni MLM | ✅ `mlm_commissions` |
| F6 | Costo bonus MLM | ✅ `mlm_bonus_payouts` |
| F7 | Costo cashback e bonus segnalazione | ✅ |
| F8 | **CAC per AQ** | ⚠️ richiede tracciamento costi |
| F9 | **Margine netto per cliente attivo** | ⚠️ |

**Sintesi:** su 45 metriche, **~24 sono già estraibili** dal database di oggi, **~13 richiedono lavoro leggero** (query + pagina), **~8 richiedono modifiche di schema**. Il backlog P1-1 (dashboard KPI) copre le prime due categorie.

## 16.3 Scheda settimanale (da compilare a mano fin da subito)

```
SETTIMANA ___ dal __/__ al __/__          Microarea: ______________

CLIENTI          Sett.  Cum.  Target      ESERCENTI        Sett. Cum. Target
Conversazioni    ____   ____  12/sett     Contattati       ____  ____ 5/sett
Registrazioni    ____   ____  5/sett      Demo             ____  ____ 2/sett
KYC completati   ____   ____  4/sett      Attivati         ____  ____ 1/sett
Prime ricariche  ____   ____  3/sett      Attivi ≥1tx/7gg  ____  ____ ≥70%
Primi acquisti   ____   ____  2,5/sett    Dormienti 14gg   ____  ____ 0
2e transazioni   ____   ____  1,5/sett
>> AQ della settimana: ____   Cumulate: ____   (target 90gg: 17)

ECONOMIA                              QUALITÀ
Volume ricariche  ______ €            Reclami aperti     ____
Prov K generato   ______ €            Anomalie referral  ____
Ky in circolo     ______              Esercenti a rischio ____

COLLO DI BOTTIGLIA DELLA SETTIMANA: ________________________________
UNA CONTROMISURA PER LA PROSSIMA:   ________________________________
```

---

# 17. Sostenibilità economica

Il file `KMoney_Modello_Economico.xlsx` allegato contiene tutte queste formule **modificabili**: cambia le celle blu (parametri) e tutto si ricalcola.

## 17.1 Le formule

**Sconto effettivo per il cliente**
```
sconto_effettivo = q × [1 − 1/(1+b)]
q = quota accettata in Ky (0,25 … 1,00)
b = bonus ricarica (0,25)
→ con b=0,25:  sconto = q × 20%
```

**Costo del bonus per il circuito, per ricarica**
```
Ky_emessi     = R × (1 + b)
EUR_incassati = R
passività_iniziale = R × b        (in Ky, valore facciale)
copertura_%   = R / [R × (1+b)] = 1/(1+b) = 80%
→ ogni Ky in circolo è coperto all'80% da euro incassati
```

Ma il costo **reale** non è `R × b`: dipende da quanto quel Ky vale in beni.
```
costo_reale_bonus = R × b × (1 − sconto_medio_esercente)
```
Se l'esercente medio accetta al 50% (= sconto effettivo 10%), il circuito acquista beni con un margine implicito. Il costo netto è assorbito **dalla rete esercenti**, non dalla cassa KMoney — **purché la rete resti circolare** (i Ky tornano in acquisti). Se non lo è, il costo torna a KMoney come passività non spendibile.

**Margine KMoney per ricarica**
```
Prov_K       = R × m                     m = margine KNM (default 30%)
commissioni  = Prov_K × (%diretta + Σ %indirette)
margine_netto = Prov_K − commissioni − quota_bonus_struttura
```

**Break-even di un evento BasiQ**
```
costo_max_BasiQ = 200 €   (tetto strutturale verificato: la somma dei payout
                           di una catena = importo del grado più alto)
ricariche_necessarie = 200 / m = 200 / 0,30 = 667 € di ricariche
→ con il gate P0-2 (6 punti da ricarica = 3 card da 120 €) si generano
  360 € di ricariche = 108 € di Prov K → copertura 54%.
  Per copertura ≥100% servono ~667 € di ricariche nella finestra BasiQ.
```

> **Numero da tenere a mente:** con il gate a 3 ricariche da 120 €, un evento BasiQ resta **coperto solo per metà**. Se vuoi che il piano si autofinanzi evento per evento, il gate va portato a **~9 punti da ricarica** (≈5 card da 120 €, 600 €) oppure il margine KNM va alzato. Questa è una decisione tua (§22, D3).

## 17.2 Margine per transazione — scenari

Con `m = 30%`:

| Scenario | Ricarica | Prov K | % dirette | % indirette | Commissioni | Margine residuo | % su ricarica |
|---|---|---|---|---|---|---|---|
| Agente nuovo (12 pt), nessuna downline | 120 € | 36,00 € | 10% | 0% | 3,60 € | 32,40 € | 27,0% |
| Agente medio (48 pt), 1 livello indiretto | 120 € | 36,00 € | 20% | 4% | 8,64 € | 27,36 € | 22,8% |
| Agente maturo (200 pt), 5 livelli pieni | 120 € | 36,00 € | 40% | 15,5% | 19,98 € | 16,02 € | 13,4% |
| Caso estremo: come sopra + 10 livelli estesi | 120 € | 36,00 € | 40% | 20,5% | 21,78 € | 14,22 € | 11,9% |

**Il margine resta positivo in tutti gli scenari realistici.** Il punto di rottura teorico (commissioni ≥ 100% di Prov K) richiede ~90 livelli aggiuntivi di agenti Top+ impilati — implausibile oggi, **non impossibile per costruzione** man mano che la rete si approfondisce. Da qui la raccomandazione P0-3 (tetto).

## 17.3 Modello economico a 12 mesi — scenario realistico

Parametri `[IPOTESI]`: ricarica media 150 €/cliente/mese fra chi ricarica; 60% dei clienti attivi ricarica in un dato mese; margine 30%; costo marketing 150 €/mese; costo piattaforma 200 €/mese; 1 agente fino al mese 5, poi 2, poi 3 dal mese 9.

| Mese | Clienti attivi | Esercenti | Ricariche | Prov K | Commissioni | Bonus struttura | Extra Bonus | Costi fissi | **Margine netto** |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 5 | 4 | 450 € | 135 € | 14 € | 0 € | 0 € | 400 € | **−279 €** |
| 2 | 15 | 7 | 1.350 € | 405 € | 47 € | 0 € | 0 € | 400 € | **−42 €** |
| 3 | 30 | 12 | 2.700 € | 810 € | 105 € | 0 € | 0 € | 400 € | **+305 €** |
| 4 | 45 | 15 | 4.050 € | 1.215 € | 176 € | 200 € | 0 € | 400 € | **+439 €** |
| 5 | 62 | 18 | 5.580 € | 1.674 € | 271 € | 0 € | 0 € | 450 € | **+953 €** |
| 6 | 80 | 21 | 7.200 € | 2.160 € | 389 € | 200 € | 0 € | 450 € | **+1.121 €** |
| 7 | 100 | 24 | 9.000 € | 2.700 € | 540 € | 200 € | 300 € | 450 € | **+1.210 €** |
| 8 | 122 | 27 | 10.980 € | 3.294 € | 731 € | 0 € | 0 € | 450 € | **+2.113 €** |
| 9 | 148 | 31 | 13.320 € | 3.996 € | 991 € | 200 € | 0 € | 500 € | **+2.305 €** |
| 10 | 176 | 35 | 15.840 € | 4.752 € | 1.312 € | 200 € | 0 € | 500 € | **+2.740 €** |
| 11 | 206 | 38 | 18.540 € | 5.562 € | 1.702 € | 200 € | 300 € | 500 € | **+2.860 €** |
| 12 | 240 | 42 | 21.600 € | 6.480 € | 2.203 € | 200 € | 3.000 € | 500 € | **+577 €** |
| **Tot** | | | **110.610 €** | **33.183 €** | **8.481 €** | **1.400 €** | **3.600 €** | **5.400 €** | **+14.302 €** |

**Break-even: mese 3.** Il crollo del margine al mese 12 è dovuto a un **Extra Bonus Top da 3.000 €** — che è esattamente il rischio di cassa segnalato in `ANALISI_MLM_SOSTENIBILITA` §3.3: sostenibile in media, ma capace di svuotare il mese in cui cade. **Serve una riserva.**

## 17.4 Esposizione Ky e riserva di cassa

Sulle stesse ipotesi, a 12 mesi:
```
Ky emessi (ricariche)     110.610 × 1,25 = 138.263 Ky
di cui promozionali                        27.653 Ky
EUR incassati                             110.610 €
Ky spesi presso esercenti (75% ipotizzato) 103.697 Ky
Ky fermi nei wallet clienti                 34.566 Ky   ← passività
```

`[RACCOMANDAZIONE]` **Policy di riserva:**
1. **Riserva Ky**: accantonare in euro il **20% del margine Prov K** mensile a copertura dei Ky in circolo. Con 33.183 € di Prov K → riserva 6.637 €.
2. **Riserva payout MLM**: accantonare il **30% del margine** finché il fondo non copre il **massimo Extra Bonus teorico attivabile** nel semestre successivo. Se in rete c'è un agente vicino a Manager, il fondo deve arrivare a 20.000 €.
3. ⚠️ **Verifica automatica nel modello**: con questi parametri la riserva payout a 12 mesi arriva a **9.955 €**, contro un Extra Bonus Manager teorico di **20.000 €**. Se un agente si avvicina a Manager, la percentuale di accantonamento va alzata o l'importo dell'Extra Bonus rivisto.
4. **Freno manuale**: approvazione admin obbligatoria (già presente nel flusso `pending→approved→paid` `[CODICE]`) usata come **controllo sostanziale** su ogni payout singolo > 1.000 €, non come formalità.

## 17.5 🔴 Stress test — "sei mesi senza nuovi agenti"

**Domanda:** il modello aziendale resta sostenibile se per sei mesi non entra nessun nuovo agente?

### Cosa succede, voce per voce

| Voce | Effetto | Perché |
|---|---|---|
| **Commissioni dirette** | ➡️ **invariate** | Dipendono dalle ricariche dei clienti degli agenti esistenti |
| **Commissioni indirette** | ➡️ invariate/in calo lento | La downline non cresce; il gating può far perdere livelli se i punti scadono |
| **Bonus di struttura** | ⬇️ **→ 0** | Scattano solo su eventi BasiQ, cioè su **nuovi agenti** entro 30 gg dall'attivazione `[CODICE]` |
| **Extra Bonus (promozioni)** | ⬇️ **→ ~0** | Le qualifiche richiedono Basic al 1° livello e colonne: senza nuovi ingressi non si sale |
| **Bonus Diretti** | ➡️ già 0 | Disattivati il 14/08/2026 `[CODICE]` |
| **Prov K (ricavo)** | ➡️ **continua** | Dipende dalle ricariche |

### Il risultato

**Per KMoney: sì, il modello regge — anzi, migliora.** Sui numeri del §17.3, sei mesi (7-12) senza nuovi agenti:

| Voce | Con nuovi agenti | Senza nuovi agenti | Δ |
|---|---|---|---|
| Prov K | 26.784 € | 21.963 € | −4.821 € |
| Commissioni | 7.479 € | 6.282 € | −1.197 € |
| Bonus di struttura | 1.000 € | **0 €** | **−1.000 €** |
| Extra Bonus | 3.600 € | **0 €** | **−3.600 €** |
| Costi fissi | 2.900 € | 2.610 € | −290 € |
| **Margine netto** | 11.805 € | **13.071 €** | **+1.266 €** |

**Per l'agente: no.** Un agente Key/Senior perde l'intera componente bonus. Sui numeri del §7.3, un agente maturo con 50 clienti passa da ~450 €/mese di commissioni **più** bonus occasionali a **solo** 450 €/mese di commissioni. Un agente Top che contava su un Extra Bonus da 3.000 € perde l'evento del tutto.

### Cosa questo significa davvero

Questo esito **non è rassicurante: è diagnostico.** Se il reddito dell'agente crolla quando la rete smette di crescere, allora una quota rilevante della sua remunerazione è ancorata al reclutamento — che è precisamente il test dell'**art. 5 c. 1 L. 173/2005**: *«l'incentivo economico primario … si fonda sul mero reclutamento di nuovi soggetti piuttosto che sulla loro capacità di vendere»*.

Oggi non è ancora "primario" (le commissioni pesano più dei bonus in un anno pieno: 8.481 € contro 5.000 €), ma il rapporto è **1,70:1** — troppo vicino alla parità per stare tranquilli, e destinato a peggiorare in fase di espansione della rete, quando gli eventi BasiQ si moltiplicano.

### Cosa va riprogettato

`[RACCOMANDAZIONE]` Tre interventi, in ordine di efficacia:

1. **P0-3 — Commissione ricorrente sulle transazioni presso esercenti.** Oggi l'agente guadagna sulla ricarica, una tantum. Aggiungere una componente su ogni transazione dei propri clienti presso gli esercenti (es. 0,5-1% del transato, a valere sulla fee esercente) crea un reddito che **cresce con l'uso e non con il reclutamento**. È la modifica che sposta strutturalmente il rapporto e la più difendibile in assoluto.
2. **Ribilanciare il mix**: portare il rapporto commissioni : bonus ad almeno **3:1** a regime (oggi 1,70:1, calcolato nel foglio *Proiezione 12 mesi*), riducendo gli Extra Bonus alti (Manager 20.000 € è un numero che, da solo, attira il tipo sbagliato di candidato) e alzando le percentuali dirette sui volumi.
3. **Ancorare i bonus di struttura al fatturato del ramo**, non al solo evento BasiQ: es. bonus Key erogato solo se il ramo ha generato ≥X € di ricariche nei 90 giorni.

---

# 18. Rischi legali, fiscali e di compliance

> **Non è un parere legale.** È una checklist di verifica costruita su fonti primarie verificate, da portare a un avvocato esperto di diritto commerciale/vendite dirette e a un commercialista. Le qualificazioni giuridiche del vostro modello concreto richiedono professionisti abilitati.

## 18.1 🔴 BLOCCHI DI COMPLIANCE

Situazioni che a mio avviso **devono essere risolte prima** del lancio pubblico o del primo reclutamento.

### BLOCCO 1 — Punti e BasiQ senza fatturato (verificato nel codice)

**Cosa succede oggi:** `RecalculateMlmPoints::handle()` promuove a BasiQ chi ha `mlmRealActivePoints() >= 12` **senza distinguere l'origine dei punti** `[CODICE]`. La sola registrazione di un cliente vale 1 punto (`mlm_point_rules`). 12 iscrizioni gratuite → evento BasiQ → fino a 200 € di bonus alla upline, zero euro di fatturato.

**Norme:** art. 5 c. 1 L. 173/2005 (incentivo primario dal reclutamento); art. 23 c. 1 lett. p) Cod. Cons. — **black list, non ammette prova contraria**, sanzione da 5.000 € a 10.000.000 € (art. 27 c. 9).

**Precedente:** AGCM PS11086 Lyoness, 3.200.000 € — cashback «aspetto secondario» rispetto ai flussi dagli affiliati.

**Fix:** P0-2 (gate BasiQ su punti da ricarica) + `min_paying_clients`. Attenuante già in essere: Bonus Diretti disattivati.

**Stato: 🔴 APERTO — bloccante.**

### BLOCCO 2 — Il pacchetto 480 €

**Rischio:** se obbligatorio, o percepito come tale, o senza controprestazione documentata → art. 4 c. 4 lett. b) e art. 6 lett. b) e c) L. 173/2005. Sanzione penale art. 7 c. 1: **arresto 6 mesi-1 anno o ammenda 100.000-600.000 €**.

**Fix:** struttura §9 (facoltativo, listino analitico, recesso 10 gg, riacquisto 90%, zero provvigioni upline) + revisione legale del contratto.

**Stato: 🔴 APERTO — bloccante per il reclutamento.**

### BLOCCO 3 — Qualificazione giuridica del Ky

**Il test, in tre passaggi** `[FONTE Normattiva/TUB]`:
1. Il Ky è valore monetario memorizzato, credito verso l'emittente, accettato da soggetti diversi dall'emittente → **potenzialmente moneta elettronica** (art. 1 c. 2 lett. h-ter TUB).
2. **MA** se rientra nell'esenzione "rete limitata" (art. 2 c. 2 lett. m d.lgs. 11/2010 = art. 3 lett. k PSD2) → **per definizione non è moneta elettronica** e non serve autorizzazione IMEL.
3. Se **non** rientra → riserva di attività art. 114-bis TUB: **serve banca o IMEL**.

**Il problema:** l'esenzione richiede *o* una rete limitata *o* una gamma **molto limitata** di beni/servizi. Un circuito multi-merceologico (10 categorie diverse è l'obiettivo dichiarato) si allontana dal secondo criterio, e le autorità valutano il primo in modo restrittivo (EBA/GL/2022/02: numero esercenti, copertura geografica, omogeneità merceologica).

**Adempimento certo se l'esenzione regge:** notifica a **Banca d'Italia** al superamento di **1.000.000 € di operazioni nei 12 mesi precedenti** (art. 37 par. 2 PSD2, art. 2 c. 4-bis d.lgs. 11/2010, Provvedimento BI 11/10/2018 modificato il 22/04/2022), con informazioni **certificate da revisore indipendente** e notifica annuale. Per strumenti prepagati il calcolo si basa sugli **importi effettivamente spesi**, non sui caricamenti.

**Nota rilevante:** anche solo il **circuito**, non i singoli esercenti, va monitorato. Sui numeri del §17.3 (110.610 € a 12 mesi) siete lontani dalla soglia. Con 3-4 microaree attive ci arrivate nel secondo anno.

**Stato: 🟠 DA VERIFICARE — non bloccante per il pilota, bloccante per la scala.**

### BLOCCO 4 — Autoconsumo e "cassetto kmoney"

**Cosa succede oggi:** `MlmWalletService` accredita **subito in Ky** commissioni e bonus sul conto dell'agente, spendibili nel circuito `[CODICE]`. L'agente è dunque insieme incaricato e consumatore del circuito.

**Norma/precedente:** Cons. Stato VI, 13/01/2020 n. 321: piramidale quando c'è «assoluta prevalenza dei proventi connessi al **reclutamento e all'autoconsumo** su quelli derivanti dalle vendite dirette» `[FONTE]`.

**Fiscale:** una provvigione pagata in Ky resta una provvigione. Art. 25-bis c. 6 DPR 600/1973: ritenuta **a titolo d'imposta**, base = provvigioni ridotte del 22% (quindi 78%), aliquota = primo scaglione IRPEF = **23%** → **17,94% effettivo**. Il codice **non modella alcuna ritenuta** `[CODICE: mlm_payouts]`.

**Fix:** (a) misurare e pubblicare il rapporto autoconsumo/vendite esterne; (b) modellare imponibile, ritenuta e netto sui payout; (c) valutare col commercialista se il momento impositivo è l'accredito in Ky o la conversione in EUR — **questione aperta e rilevante**.

**Stato: 🔴 APERTO.**

### BLOCCO 5 — Adempimenti amministrativi mai modellati

| Adempimento | Norma | Stato |
|---|---|---|
| SCIA/comunicazione al Comune per vendita diretta | art. 19 d.lgs. 114/1998 via art. 2 L. 173/2005 | ❓ da verificare |
| **Comunicazione elenco incaricati all'autorità di P.S.** | idem | ❓ |
| **Tesserino di riconoscimento** numerato, con foto, aggiornato annualmente, esposto in modo visibile | art. 2 e 3 L. 173/2005 | 🔴 **non esiste nel software** |
| Incarico **provato per iscritto** con indicazione di diritti art. 4 c. 3 e 6 | art. 4 c. 2 | ⚠️ contratto esiste; verificare che contenga espressamente recesso e riacquisto |
| Divieto di riscossione da parte dell'incaricato senza **autorizzazione scritta espressa** | art. 4 c. 8 | 🔴 **rilevante**: oggi l'agente può ricevere e disporre Ky. Serve autorizzazione scritta |
| Provvigioni «sugli affari che, accettati, hanno avuto **regolare esecuzione**», misura e modalità **per iscritto** | art. 4 c. 9 | ⚠️ verificare che il piano compensi sia allegato al contratto |

**Stato: 🔴 APERTO — il tesserino e l'autorizzazione scritta alla riscossione sono i due più concreti.**

### BLOCCO 6 — Comunicazione del 25%

Presentare il bonus come "sconto del 25%" è una pratica commerciale ingannevole sotto gli artt. 20-21 Cod. Cons. Il precedente ARIIX/NewAge include 160.000 € di sanzione per **marketing occulto** sui social (art. 22 c. 2 e art. 23 c. 1 lett. aa) — cioè per non aver reso riconoscibile la finalità commerciale.

**Fix:** formula corretta §7.1 ovunque; identificazione «Agente KMoney» in ogni comunicazione; modulo 5 dell'academy eliminatorio; condizioni pubblicate su `/legale/limiti` prima della promozione.

**Stato: 🟠 RISOLVIBILE SUBITO — dipende solo da voi.**

## 18.2 Checklist per l'avvocato

**L. 173/2005 — struttura di rete**
- ☐ Il piano compensi supera il test dell'art. 5 c. 1? Quale % del reddito medio deriva da vendite a clienti esterni vs eventi di rete? *(dato calcolabile: §17.5 → oggi 1,16:1)*
- ☐ Il pacchetto 480 € integra l'art. 6 lett. b) o c)?
- ☐ Il contratto agente contiene recesso 10 gg lavorativi (art. 4 c. 3) e riacquisto ≥90% (art. 4 c. 6)?
- ☐ Serve autorizzazione scritta espressa per la riscossione tramite wallet (art. 4 c. 8)?
- ☐ Le provvigioni sono definite «su affari che hanno avuto regolare esecuzione» e messe per iscritto (art. 4 c. 9)?
- ☐ SCIA, elenco incaricati alla P.S., tesserini: chi li gestisce e con quale processo?
- ☐ La retrocessione automatica di qualifica (senza preavviso né periodo di grazia) è compatibile col contratto?

**Codice del Consumo**
- ☐ La comunicazione del 25% regge sotto artt. 20-22?
- ☐ Gli agenti sono identificabili come tali in ogni canale (art. 22 c. 2)?
- ☐ Il modello espone a contestazione ex art. 23 c. 1 lett. p)?
- ☐ Diritto di recesso 14 gg per i **clienti** (contratti a distanza, artt. 45 ss.): si applica alla KY Card? Come si esercita se il Ky è già stato speso?

**Servizi di pagamento**
- ☐ Il Ky rientra nell'esenzione rete limitata? Con quale motivazione documentata?
- ☐ Chi monitora la soglia di 1 M€ e chi certifica i dati?
- ☐ Serve autorizzazione IMEL/IP nel piano a 24 mesi?
- ☐ Il cashback su transazioni è distinguibile dal «beneficio commisurato alla giacenza» vietato dall'art. 114-bis c. 3 TUB?

**Privacy**
- ☐ Base giuridica per il trattamento dei dati cliente da parte dell'agente
- ☐ L'agente è titolare autonomo o responsabile ex art. 28 GDPR? Serve un DPA
- ☐ Consenso marketing separato; gestione dei contatti su WhatsApp personale dell'agente
- ☐ Informativa su profilazione (offerte personalizzate, missioni)
- ☐ Consenso scritto per ogni testimonianza pubblicata

**AML/KYC**
- ☐ KMoney è soggetto obbligato ex art. 3 d.lgs. 231/2007? *(non lo è **su questa base** se opera in esenzione rete limitata e non è IMEL/IP — ma va verificato che non ricorrano altri titoli)*
- ☐ Se lo diventa: adeguata verifica a 15.000 € (occasionale/frazionata), 1.000 € (trasferimento fondi), **qualsiasi importo se tramite agenti o soggetti convenzionati** (art. 17 c. 6)
- ☐ Il KYC attuale (visura + documento) è adeguato? Identificazione non in presenza ex art. 19 (SPID/eIDAS)?

## 18.3 Checklist per il commercialista

- ☐ **Inquadramento agenti**: incaricato occasionale (< 5.000 € reddito annuo, art. 3 c. 4 L. 173/2005 = ~6.410,26 € lordi al 78%), incaricato abituale, o contratto di agenzia (con Enasarco)?
- ☐ Ritenuta 23% su base 78% (art. 25-bis c. 6 DPR 600/73): **da implementare nel software**
- ☐ Contributi gestione separata INPS oltre 5.000 €: 1/3 incaricato, 2/3 impresa
- ☐ **Il Ky è un buono-corrispettivo multiuso** (art. 6-quater DPR 633/72)? Se sì: il caricamento non è operazione IVA, l'IVA scatta all'utilizzo presso l'esercente
- ☐ **Art. 6-quater c. 4**: le commissioni del gestore del circuito («servizi di distribuzione e simili») sono **autonomamente rilevanti ai fini IVA**
- ☐ **Trattamento del bonus 25%**: sconto, abbuono ex art. 26 DPR 633/72, premio, o corrispettivo? *(area esplicitamente non risolta dalla ricerca — priorità alta)*
- ☐ **Trattamento del cashback** in moneta interna, e rischio di riqualificazione come provvigione se erogato a fronte di attività di vendita
- ☐ Momento impositivo della provvigione in Ky: accredito o conversione?
- ☐ Fatturazione elettronica del pacchetto 480 € con dettaglio voci
- ☐ Ricavo da margine KNM: qualificazione e momento di rilevazione
- ☐ Trattamento contabile della passività Ky in bilancio

## 18.4 Riepilogo dei livelli di certezza

| Tema | Fonte | Certezza |
|---|---|---|
| Testo L. 173/2005 artt. 1-7 | Normattiva, vigente 14/08/2026 | **DOCUMENTATO** |
| **Assenza di soglia in euro per il kit iniziale** | art. 4 c. 4 e art. 6 | **DOCUMENTATO** (la soglia non esiste) |
| Soglia riacquisto 90% | art. 4 c. 6 | **DOCUMENTATO** |
| Sanzioni 100k-600k € (penale) / 1.500-5.000 € (amm.) | art. 7 | **DOCUMENTATO** |
| Ritenuta a titolo d'imposta su base 78% | art. 25-bis c. 6 DPR 600/73 | **DOCUMENTATO** |
| Aliquota 23% al 2026 | art. 11 TUIR, L. 199/2025 | **PROBABILE** |
| Soglia 5.000 € netti / ~6.410,26 € lordi | art. 3 c. 4 + calcolo | **PROBABILE** (valore derivato, non normato) |
| **Provv. AGCM 30423 = PS12323 ARIIX/NEWAGE, 13/12/2022, 960.000 €** | agcm.it | **DOCUMENTATO** |
| Lyoness PS11086, 19/12/2018, 3.200.000 €, cashback «aspetto secondario» | agcm.it | **DOCUMENTATO** |
| OneCoin PS10550, 10/08/2017, 2.595.000 € | agcm.it | **DOCUMENTATO** |
| Cons. Stato VI n. 321/2020 — autoconsumo + reclutamento | giustizia-amministrativa.it | **DOCUMENTATO** |
| Esenzione reti limitate | art. 2 c. 2 lett. m d.lgs. 11/2010 | **DOCUMENTATO** |
| Soglia notifica 1 M€ / 12 mesi | art. 37 PSD2 + Provv. BI 11/10/2018 | **DOCUMENTATO** |
| Riserva emissione moneta elettronica | artt. 114-bis e 1 c. 2 lett. h-ter TUB | **DOCUMENTATO** |
| **Qualificazione del Ky nel caso concreto** | — | **DA VERIFICARE CON PROFESSIONISTA** |
| Soglie adeguata verifica AML | art. 17 d.lgs. 231/2007 | **DOCUMENTATO** |
| Voucher monouso/multiuso | artt. 6-bis/ter/quater DPR 633/72 | **DOCUMENTATO** |
| **Trattamento IVA/reddituale del cashback in moneta interna** | — | **NON VERIFICATO — priorità alta** |
---

# 19. Audit dell'app e gap analysis

## 19.1 Stato per area

**Legenda:** 🟢 presente e completo · 🟡 parziale · 🔵 documentato non implementato · 🔴 assente

| Area | Stato | Dettaglio verificato | Gap |
|---|---|---|---|
| Registrazione e onboarding | 🟢 | `AuthController`, `EnsureOnboardingComplete`, tutorial, `OnboardingControllerTest` | — |
| KYC | 🟡 | Upload doc, workflow approve/reject/request-docs, notifiche, bonus benvenuto | Nessun provider di identità elettronica (SPID/eIDAS); nessuna scadenza documenti; nessuna riverifica periodica |
| Wallet e contabilità | 🟢 | Partita doppia, `TransferBookingService` con lock, integrità notturna + oraria, `/health` con dead-man's switch, riconciliazione, verifica contesa | Eccellente. Nulla da segnalare |
| Ricariche (KY Card) | 🟢 | Catalogo card, Stripe + bonifico con conferma admin, snapshot prezzo/Ky, storico | — |
| **Bonus 25%** | 🟡 | Configurabile per card (`bonus_type`, `bonus_value`) | 🔴 **Ky promozionali non segregati, nessuna scadenza, nessun limite di spesa, non misurabili separatamente** |
| Trasferimenti | 🟢 | Fido, massimali, PIN, TOTP sopra soglia, beneficiari salvati, programmati, ricorrenti, link di pagamento | — |
| Incasso esercente | 🟢 | QR statico/dinamico, NFC, codice, sonic, kit merchant PDF | — |
| Carte NFC | 🟢 | Emissione singola/bulk, PIN con soglia, spedizione, revoca, log | — |
| Esercenti/directory | 🟢 | `/aziende` con mappa, geocoding, filtro % e settore, scheda pubblica, slug | Nessuna recensione/valutazione |
| Prodotti e offerte | 🟢 | Listing con categorie/sottocategorie, mix Ky/EUR, offerte a tempo con snapshot | — |
| Cashback | 🟡 | Regole con targeting, finanziate dal **conto sistema** | 🔴 Manca la variante **a carico dell'esercente** — che è il meccanismo Satispay più efficace |
| Referral | 🟡 | Codice, link, 3 livelli, non cumulativi, idempotente | 🔴 Bonus `amico` alla **registrazione** invece che al primo acquisto; 🔴 **nessun antifrode** |
| Gerarchia agenti | 🟢 | Closure table, albero visuale, spostamento nodo, root configurabile | — |
| Punti / qualifiche | 🟢 | Regole editabili, ledger con finestre, retrocessione bidirezionale, metriche omaggio, ricalcolo notturno | `min_paying_clients` mancante |
| Commissioni | 🟢 | Dirette + indirette + estese, gating, snapshot margine, idempotente, testato contro le slide | 🔴 **Nessun tetto di sicurezza**; base = ricarica, non transazione |
| Bonus struttura/award | 🟢 | Cascata per posizione, snapshot rank, Extra Bonus, Diretti (disattivati) | 🔴 **Gate BasiQ assente** |
| Payout agente | 🟢 | Aggregazione, stati, soglia, dati di pagamento, cassetto Ky con quota prelevabile | 🔴 **Nessuna ritenuta/imponibile/netto**; nessuna fattura |
| Notifiche | 🟢 | 60+ classi, email + in-app + web push, preferenze per gruppo | 🟡 Nessuna notifica di **funnel** (primo acquisto, seconda transazione, riattivazione) |
| Code e scheduler | 🟢 | 11 job, `withoutOverlapping`, log dedicati, queue worker su cron | — |
| Audit e log | 🟢 | `audit_logs` con indice antifrode, `login_logs`, export CSV, sessioni | — |
| Test | 🟢 | 95 file, copertura MLM molto densa incl. verifica contro le slide | Nessun test end-to-end del funnel commerciale |
| **Antifrode** | 🔴 | Solo audit + nuovo IP | Nessuna regola su referral, cluster, velocity |
| **Dashboard KPI funnel** | 🔴 | `/admin/analytics` e `/admin/report` sono transazionali | Nessuna vista di funnel |
| **Academy/certificazione** | 🔴 | — | Tutto da costruire |
| **CRM agente** | 🔴 | — | Tutto da costruire |
| **Landing page agente** | 🔴 | Solo `/invita` | — |
| **Missioni** | 🔴 | — | — |
| **Reclami** | 🔴 | Solo `support_messages` generico | Nessun registro strutturato |

## 19.2 Gap analysis dettagliata

Formato: **Problema → Valore → Rischio → Priorità → Dipendenze → Componenti Laravel → DB → Autorizzazioni → Eventi → Test → Accettazione → Complessità**

### P0-1 · Segregare i Ky promozionali

- **Problema.** I 25 Ky bonus sono indistinguibili dai 100 acquistati. Non misurabili, non limitabili, non scadenzabili, non contabilizzabili separatamente. `[CODICE]`
- **Valore.** Rende governabile l'esposizione, apre la strada a scadenze e limiti, e permette di rispondere onestamente alle domande "quando", "dove", "limiti", "scadenze" — che oggi non hanno risposta.
- **Rischio.** Alto se **non** fatto (comunicazione non verificabile, passività fuori controllo). Medio se fatto (tocca la contabilità, che è il cuore critico del sistema).
- **Priorità: P0** · **Dipendenze:** nessuna
- **Componenti.** `LedgerEntry`, `Account`, `TransferBookingService`, `KyCardPurchase`, viste saldo, estratto conto
- **DB.** Nuova `ky_promotional_ledger` (o colonna `nature` ∈ {purchased, promotional, commission} su `ledger_entries` + accessor `promotionalBalance()`). **Precedente riusabile:** `MlmWalletService::withdrawableBalance()` già segrega una quota del saldo agente `[CODICE]`
- **Autorizzazioni.** Solo admin per le configurazioni
- **Eventi.** Nessuno nuovo in v1
- **Test.** Unit su calcolo saldo per natura; feature su acquisto card con bonus; **test di non-regressione sull'integrità contabile** (la somma dei saldi deve restare zero)
- **Accettazione.** Dato un acquisto di card da 100 € con bonus 25%, il conto mostra saldo totale 125 Ky, di cui 25 promozionali; `accounting:verify-integrity` passa
- **Complessità: M** (3-5 gg) — la parte delicata è non rompere l'invariante contabile

### P0-2 · Gate BasiQ su punti da ricarica

- **Problema.** BasiQ scatta con 12 punti di qualunque origine `[CODICE: RecalculateMlmPoints]`
- **Valore.** Chiude il percorso a costo zero. **È il singolo intervento a più alto rapporto impatto/costo dell'intero backlog.**
- **Rischio.** Basso tecnicamente, alto se non fatto (BLOCCO 1)
- **Priorità: P0** · **Dipendenze:** nessuna
- **Componenti.** `RecalculateMlmPoints`, `SystemSetting`, pagina `/admin/mlm-impostazioni`
- **DB.** `system_settings.mlm_basiq_min_deposit_points` (default 6, editabile)
- **Autorizzazioni.** Backoffice
- **Eventi.** Nessuno. Audit log esteso con la scomposizione dei punti
- **Test.** Agente con 12 punti tutti da registrazione → **non** BasiQ. Con 6 da ricarica + 6 da registrazione → BasiQ. Con soglia a 0 → comportamento attuale (retrocompatibilità)
- **Accettazione.** `RecalculateMlmPointsCommandTest` esteso, verde
- **Complessità: S** (mezza giornata)

### P0-3 · Tetto di sicurezza sulle commissioni + base su transazioni

Due interventi correlati, il secondo dei quali è strategico.

**(a) Tetto.** La somma diretta+indiretta su una singola riga di Prov K non supera mai `mlm_commission_cap_percent` (default 80%).
- **Componenti.** `MlmCommissionEngine::processMonth()` — accumulatore per riga di base e `min()` finale prima della creazione delle `MlmCommission`
- **DB.** `system_settings.mlm_commission_cap_percent`
- **Test.** Catena artificiale profonda che supererebbe il 100% → il totale si ferma all'80%
- **Complessità: S**

**(b) Base commissionabile su transazioni.** Aggiungere una componente commissionale sul **transato dei clienti presso gli esercenti**, accanto (o in sostituzione parziale) a quella sulla ricarica.
- **Valore.** Sposta il reddito dell'agente da "quanto ho fatto ricaricare" a "quanto usano" — cioè da un evento una tantum a una ricorrenza legata a fatturato reale. **È l'intervento che risponde strutturalmente allo stress test §17.5 e al test dell'art. 5.**
- **Componenti.** `MlmCommissionBaseLedgerEntry` (aggiungere `source_kind` ∈ {deposit, transaction}), hook post-booking in `TransferBookingService`, `MlmCommissionEngine`
- **DB.** `mlm_commission_base_ledger.source_kind`; nuovo parametro `mlm_transaction_margin_percent`
- **Test.** Transazione cliente→esercente genera riga di base; il run mensile la remunera; idempotenza garantita
- **Accettazione.** Un cliente che ricarica 0 € ma spende 500 € nel mese genera commissione all'agente
- **Complessità: L** (5-8 gg) — è la modifica più impattante e va progettata insieme alla politica di fee esercente
- **Priorità: P0 (a) / P0-bis (b)** — (b) può seguire di 2-3 settimane ma non oltre

### P0-4 · Bonus referral al primo acquisto

- **Problema.** `awardTier(TIER_AMICO)` alla registrazione, addebitato all'agente **anche in scoperto** `[CODICE]`
- **Valore.** Allinea l'incentivo all'attivazione (principio P5), protegge il conto dell'agente, elimina le iscrizioni inerti
- **Priorità: P0** · **Dipendenze:** P1-2 (tracciamento primo acquisto) — realizzabili insieme
- **Componenti.** `ReferralBonusService`, `AuthController`, nuovo listener su transfer completato
- **DB.** `users.first_merchant_purchase_at`
- **Eventi.** Nuovo `FirstMerchantPurchase` con listener che chiama `awardTier`
- **Test.** Registrazione senza acquisto → nessun bonus. Primo acquisto entro 30 gg → bonus. Oltre 30 gg → nessun bonus. Idempotenza
- **Accettazione.** `ReferralBonusServiceTest` esteso, verde
- **Complessità: M** (2-3 gg)

### P0-5 · Blocco tecnico delle 48 ore

- **Problema.** Nessun vincolo temporale fra informativa e firma del contratto agente
- **Valore.** Rende **verificabile** il periodo di riflessione — prova documentale in caso di contestazione
- **Priorità: P0** (rispetto al reclutamento) · **Complessità: S** (1 gg)
- **Componenti.** `MlmAgentContractController`, middleware `agent.contract`
- **DB.** `users.mlm_informative_delivered_at`
- **Test.** Firma prima di 48h dalla consegna → bloccata con messaggio chiaro

### P1 — Alta priorità (post-lancio pilota)

| ID | Intervento | Valore | Componenti | DB | Complessità |
|---|---|---|---|---|---|
| **P1-1** | **Dashboard KPI funnel** `/admin/kpi` | Rende misurabile tutto il §16 | nuovo `KpiController` + viste; query aggregate | viste materializzate o cache | **M** (4-5 gg) |
| **P1-2** | **Tracciamento eventi funnel** | Prerequisito di quasi tutto | listener su transfer | `users`: `first_purchase_at`, `second_purchase_at`, `qualified_at`, `first_recharge_at` | **S** (1-2 gg) |
| **P1-3** | Verifica "2 utilizzi reali" per candidati agente | Rende il funnel selettivo automatico | `MlmAgentRequestController` | usa P1-2 | **S** |
| **P1-4** | **Stato Agente Attivo/Dormiente** | Governo della rete, criterio oggettivo | comando schedulato + colonna | `users.mlm_activity_status`, `mlm_last_qualified_activity_at` | **M** |
| **P1-5** | Documento informativo precontrattuale + tracciamento consegna | Compliance (BLOCCO 2) | nuova pagina + PDF | `mlm_informative_deliveries` | **M** |
| **P1-6** | **Academy + quiz + certificazione** | Compliance + qualità | nuovo modulo | `academy_modules`, `academy_progress`, `academy_quiz_results` | **L** (8-10 gg) |
| **P1-7** | **Antifrode referral** | Protegge il conto agente e la difendibilità | service + job notturno | `referral_flags` | **M** (3-4 gg) |
| **P1-8** | **Registro reclami e segnalazioni** | Compliance + KPI E3 | CRUD admin + form pubblico | `complaints` | **M** |
| **P1-9** | **Ritenuta e imponibile sui payout** | Compliance fiscale (BLOCCO 4) | `MlmPayoutService`, PDF | `mlm_payouts`: `taxable_amount`, `withholding_rate`, `withholding_amount`, `net_amount` | **M** — **richiede il parere del commercialista prima** |
| **P1-10** | **Cashback a carico dell'esercente** | Leva Satispay più efficace (P6) | `CashbackService`, `CashbackRule` | `cashback_rules.funding_account_id` | **M** |
| **P1-11** | Notifiche di funnel (1° acquisto, 2ª tx, riattivazione) | Automatizza campagne §14.2 | job schedulato + notifiche | — | **M** |
| **P1-12** | **Tesserino di riconoscimento agente** (PDF numerato con foto, scadenza annuale) | Adempimento art. 2-3 L. 173/2005 | controller + dompdf (già in composer) | `users`: `badge_number`, `badge_photo_path`, `badge_valid_until` | **S-M** |

### P2 — Media priorità

| ID | Intervento | Complessità |
|---|---|---|
| **P2-1** | Landing page personale agente (`/a/{codice}`) con QR | M |
| **P2-2** | CRM leggero agente (pipeline lead, note, promemoria) | L |
| **P2-3** | Missioni settimanali configurabili | L |
| **P2-4** | Scadenza dei Ky promozionali *(dipende da P0-1)* | M |
| **P2-5** | Limite % di Ky promozionali spendibili per scontrino *(dipende da P0-1)* | M |
| **P2-6** | Simulatore aggregato di sostenibilità del piano (proiezione di cassa) | L |
| **P2-7** | Esportazioni amministrative estese (CSV/XLSX su tutte le entità) | S |
| **P2-8** | Recensioni/valutazioni esercenti nella directory | M |
| **P2-9** | Kit agente digitale (materiali brandizzati generati con dati dell'agente) | M |
| **P2-10** | Gestione rateizzazione pacchetto 480 € *(riuso di `payment_plans`)* | M |
| **P2-11** | Fatturazione elettronica pacchetto e provvigioni | L |
| **P2-12** | Indice di circolarità in dashboard | S |

---

# 20. Backlog ordinato

## 20.1 Sprint 0 — Prima di qualunque lancio pubblico (3-5 giorni)

| # | ID | Intervento | Stima | Bloccante per |
|---|---|---|---|---|
| 1 | **P0-2** | Gate BasiQ su punti da ricarica | 0,5 gg | Reclutamento agenti |
| 2 | **P0-3a** | Tetto di sicurezza commissioni | 0,5 gg | — |
| 3 | **P0-5** | Blocco 48h fra informativa e firma | 1 gg | Reclutamento agenti |
| 4 | **P1-2** | Tracciamento eventi funnel | 1,5 gg | Tutto il resto |
| 5 | — | Aggiunta `min_paying_clients` a `mlm_rank_requirements` | 0,5 gg | — |
| 6 | — | **Pubblicazione condizioni corrette del 25%** su `/legale/limiti` | 0,5 gg | Comunicazione pubblica |

## 20.2 Sprint 1 — Prime 4 settimane (10-12 giorni)

| # | ID | Intervento | Stima |
|---|---|---|---|
| 7 | **P0-1** | Segregazione Ky promozionali | 4 gg |
| 8 | **P0-4** | Bonus referral al primo acquisto | 2,5 gg |
| 9 | **P1-1** | Dashboard KPI funnel | 4,5 gg |
| 10 | **P1-12** | Tesserino agente | 1,5 gg |

## 20.3 Sprint 2 — Settimane 5-10 (15-18 giorni)

| # | ID | Intervento | Stima |
|---|---|---|---|
| 11 | **P0-3b** | Base commissionabile su transazioni | 7 gg |
| 12 | **P1-7** | Antifrode referral | 3,5 gg |
| 13 | **P1-4** | Stato Agente Attivo/Dormiente | 3 gg |
| 14 | **P1-10** | Cashback a carico esercente | 3 gg |
| 15 | **P1-8** | Registro reclami | 2,5 gg |

## 20.4 Sprint 3 — Settimane 11-18

P1-5 (documento informativo), P1-6 (academy), P1-9 (ritenute — **dopo il parere fiscale**), P1-11 (notifiche funnel), P1-3.

## 20.5 Riepilogo delle modifiche tecniche

**Migration da creare (11)**
```
2026_08_XX_add_nature_to_ledger_entries              (P0-1)
2026_08_XX_add_mlm_basiq_min_deposit_points          (P0-2)
2026_08_XX_add_mlm_commission_cap_percent            (P0-3a)
2026_08_XX_add_funnel_timestamps_to_users            (P1-2)
2026_08_XX_add_min_paying_clients_to_rank_req        (Sprint 0)
2026_08_XX_add_informative_delivered_at_to_users     (P0-5)
2026_08_XX_add_source_kind_to_commission_base_ledger (P0-3b)
2026_08_XX_create_referral_flags_table               (P1-7)
2026_08_XX_create_complaints_table                   (P1-8)
2026_08_XX_add_withholding_to_mlm_payouts            (P1-9)
2026_08_XX_add_badge_fields_to_users                 (P1-12)
```

**Model da modificare:** `LedgerEntry`, `Account`, `User`, `KyCardPurchase`, `SystemSetting`, `MlmRankRequirement`, `MlmCommissionBaseLedgerEntry`, `MlmPayout`, `CashbackRule`

**Service da modificare:** `TransferBookingService` (hook post-booking), `MlmCommissionEngine` (cap + base transazioni), `MlmPointsService` (base per transazione), `ReferralBonusService` (trigger), `MlmRankEngine` (`min_paying_clients`), `MlmPayoutService` (ritenuta), `CashbackService` (funding)

**Service da introdurre:** `FunnelTrackingService`, `ReferralFraudService`, `KpiAggregationService`, `PromotionalKyService`

**Comandi/job:** `RecalculateMlmPoints` (gate), nuovo `mlm:sync-agent-activity`, nuovo `referral:scan-fraud`, nuovo `kpi:refresh`

**Notifiche nuove:** `FirstPurchaseReminderNotification`, `SecondTransactionNudgeNotification`, `ReactivationNotification`, `MerchantDormantNotification`, `AgentDormantNotification`

**Pagine admin nuove:** `/admin/kpi`, `/admin/reclami`, `/admin/antifrode`, `/admin/academy`

**Route/API:** endpoint API v1 per KPI (opzionale); `/a/{codice}` landing agente (P2)

**Policy:** nuova `ComplaintPolicy`; estensione `backoffice` per le nuove pagine

**Test da scrivere:** ~35-40 nuovi test — feature per ogni intervento P0/P1, unit per i calcoli (saldo per natura, cap commissioni, ritenuta), **integrazione end-to-end del funnel** (registrazione → KYC → ricarica → acquisto → bonus referral)

## 20.6 🔴 Dati che NON devono essere alterati

| Dato | Perché | Verifica |
|---|---|---|
| `ledger_entries` esistenti | Contabilità storica. Ogni P0-1 deve solo **aggiungere** una colonna, mai riscrivere righe | `accounting:verify-integrity` prima e dopo |
| `mlm_point_ledger` storico | Righe emesse con regole precedenti restano valide come emesse `[DOC]` | Confronto conteggi |
| `mlm_commission_base_ledger.knm_margin_percent` | Snapshot per deposito: **mai** ricalcolare retroattivamente | — |
| `mlm_commissions` / `mlm_bonus_payouts` già erogati | Nessun ricalcolo retroattivo, per principio già stabilito | — |
| `mlm_bonus_events.upline_ranks_at_trigger` | Snapshot del rank al momento dell'evento | — |
| `contract_signatures` / `mlm_agent_contract_signatures` | Valore probatorio | — |
| `transfers` | Solo append | — |
| `audit_logs` | Solo append | — |
| `listing_offers` | Mai cancellate fisicamente (richiesta esplicita) `[DOC]` | — |

---

# 21. Piano di test e deploy

## 21.1 Strategia di test

| Livello | Cosa | Come |
|---|---|---|
| **Unit** | Calcolo saldo per natura, cap commissioni, ritenuta, sconto effettivo | PHPUnit |
| **Feature** | Ogni intervento P0/P1, con caso limite e idempotenza | PHPUnit |
| **Integrazione** | Funnel completo: registrazione → KYC → ricarica → acquisto → bonus referral → punti → commissione | Nuovo `FunnelEndToEndTest` |
| **Regressione contabile** | `accounting:verify-integrity` deve passare dopo ogni modifica al ledger | Comando artisan in CI |
| **Regressione MLM** | La suite MLM esistente (~20 file) deve restare verde, incluso `MlmSlideCompensationTablesTest` | PHPUnit |
| **E2E browser** | Registrazione, KYC upload, acquisto card, pagamento QR | Playwright (già configurato) |
| **Manuale su staging** | Percorso completo con account reali su `kosmopay.it` | Checklist §21.3 |

**Regola:** nessun deploy in produzione se `MlmSlideCompensationTablesTest` o `accounting:verify-integrity` falliscono.

## 21.2 Procedura di deploy — kosmopay.it → kmoney.it

> **⚠️ Vincolo noto e critico.** In produzione `kmoney.it` il codice viene deployato ma `php artisan migrate` **non viene eseguito automaticamente**, il che ha già causato errori 500 "Unknown column" `[memoria di progetto]`. Ogni migration di questo backlog va convertita in **SQL ri-eseguibile a blocchi**, applicata via phpMyAdmin/cPanel, con **guardia sull'INSERT in `migrations`** per non far ripartire due volte la stessa migration.

### Fase A — Staging (kosmopay.it)

```
□ 1. Backup completo DB staging (mysqldump) + snapshot filesystem
□ 2. Merge su branch staging, deploy via .cpanel.yml
□ 3. php artisan migrate --force
□ 4. php artisan config:cache && route:cache && view:cache
□ 5. php artisan accounting:verify-integrity            → deve passare
□ 6. Suite completa PHPUnit                              → verde
□ 7. Checklist manuale §21.3                             → completa
□ 8. Osservazione 48h: log applicativi, Sentry, job schedulati
□ 9. Verifica che i job MLM notturni girino senza errori
```

### Fase B — Produzione (kmoney.it)

```
□ 10. Backup DB produzione — VERIFICATO RIPRISTINABILE, non solo eseguito
□ 11. Finestra a basso traffico (domenica 06:00-08:00). MAI il 1° del mese
      (mlm:calculate-commissions gira alle 02:00) né di mercoledì
      (mlm:calculate-weekly-bonuses alle 04:00)
□ 12. Modalità manutenzione: php artisan down --secret=...
□ 13. Applicare l'SQL a blocchi ri-eseguibili via phpMyAdmin, uno alla volta,
      verificando l'esito di ogni blocco prima del successivo
□ 14. Inserire manualmente le righe in `migrations` (con guardia
      NOT EXISTS) per allineare lo stato
□ 15. Deploy del codice
□ 16. php artisan config:clear && cache:clear && config:cache
□ 17. php artisan accounting:verify-integrity --quick    → deve passare
□ 18. Smoke test §21.4
□ 19. php artisan up
□ 20. Monitoraggio 2h: Sentry, log, /health
□ 21. Verifica notturna: che i job schedulati siano girati
```

### Rollback

| Scenario | Azione | Tempo |
|---|---|---|
| Errore applicativo, DB integro | Rollback del codice al commit precedente, `config:cache` | < 10 min |
| Migration parzialmente applicata | SQL di rollback **preparato in anticipo per ogni blocco** (obbligatorio), + rimozione riga da `migrations` | < 30 min |
| Corruzione contabile | 🔴 `php artisan down` immediato + ripristino dump + analisi offline. **Mai "sistemare in produzione"** | 1-2 h |
| Job MLM che ha calcolato male | I run sono idempotenti e hanno stato `failed`: annullare il run, correggere, rieseguire. `MlmCommissionRun` supporta il replay `[CODICE]` | variabile |

### Backup

- **Prima di ogni deploy:** dump completo, **testato con un ripristino su un DB di scarto**
- **Ricorrente:** giornaliero con ritenzione 30 giorni
- **Prima di ogni intervento sul ledger:** dump dedicato di `ledger_entries`, `transfers`, `accounts`, conservato 90 giorni

## 21.3 Checklist manuale su staging

```
ONBOARDING
□ Registrazione privato con link referral → referred_by_user_id valorizzato
□ Completamento profilo, KYC upload, approvazione admin
□ Bonus benvenuto erogato (se configurato)
□ Bonus referral: verificare il NUOVO trigger (primo acquisto), non la registrazione

WALLET
□ Acquisto KY Card via Stripe test → accredito corretto, base + bonus
□ Acquisto via bonifico → conferma admin → accredito
□ VERIFICA P0-1: saldo mostra totale e quota promozionale separata
□ Trasferimento a un altro utente con PIN e TOTP
□ Pagamento presso esercente con QR dinamico
□ Pagamento con carta NFC

ESERCENTE
□ Registrazione azienda + KYC + approvazione
□ Impostazione accepted_ky_percentage
□ Caricamento prodotto con ky_percentage
□ Creazione offerta a tempo → scadenza automatica
□ Kit merchant PDF generato correttamente
□ Comparsa in /aziende e sulla mappa con badge % corretto

MLM
□ Registrazione cliente sotto agente → punti registrazione
□ Ricarica cliente → punti deposito + riga base commissioni
□ VERIFICA P0-2: agente con 12 pt tutti da registrazione NON diventa BasiQ
□ VERIFICA P0-2: agente con 6 pt da ricarica + 6 da registrazione diventa BasiQ
□ mlm:recalculate-points → qualifiche corrette
□ mlm:calculate-commissions → importi corretti (confronto col simulatore)
□ VERIFICA P0-3a: catena profonda → totale commissioni ≤ 80% di Prov K
□ mlm:calculate-weekly-bonuses → cascata corretta
□ Cassetto Ky accreditato
□ Richiesta payout → approvazione → pagato

COMPLIANCE
□ Condizioni del 25% pubblicate e corrette su /legale/limiti
□ VERIFICA P0-5: firma contratto agente bloccata prima di 48h
□ Contratto agente contiene recesso 10 gg e riacquisto 90%
□ Tesserino agente generato con numero, foto e scadenza

INTEGRITÀ
□ accounting:verify-integrity → nessun disallineamento
□ /health → verde, heartbeat aggiornato
□ Tutti i job schedulati eseguiti senza errori nelle 48h
```

## 21.4 Smoke test post-deploy produzione (10 minuti)

```
□ Login utente reale
□ /dashboard carica, saldo corretto
□ /aziende carica con mappa
□ Un pagamento reale da 1 € fra due conti di test
□ accounting:verify-integrity --quick → verde
□ /health → verde
□ Sentry: nessun nuovo errore negli ultimi 10 minuti
□ Un utente reale conferma di riuscire ad accedere
```

---

# 22. Decisioni che devi prendere

In ordine di urgenza. Ognuna sblocca lavoro concreto.

| # | Decisione | Opzioni | Mia raccomandazione | Blocca |
|---|---|---|---|---|
| **D1** | **Autorizzi lo Sprint 0?** (5 interventi, 3-5 gg, nessuno tocca la contabilità) | Sì / Solo alcuni / No | **Sì, tutto.** Nessun intervento è reversibile con difficoltà e tutti chiudono rischi aperti | Tutto il resto |
| **D2** | **Il bonus del 25% resta al 25% su tutti i tagli?** Oggi è per-card e non conosco i valori in produzione | Uniforme 25% / Crescente per taglio / Da rivedere | **Crescente** (es. 15% sotto 120 €, 25% da 120 €, 30% da 600 €): premia l'impegno senza aumentare l'esposizione media | Comunicazione, materiali |
| **D3** | **Quanto deve costare un evento BasiQ in ricariche?** | 3 card da 120 € (copertura 54%) / 5 card (copertura 90%) / altro | **5 card = 9-10 punti da ricarica.** Un piano che si autofinanzia evento per evento è più difendibile e più sano | P0-2 (il valore del parametro) |
| **D4** | **I Ky promozionali scadono?** | No (oggi) / 12 mesi / 24 mesi | **12 mesi**, comunicati prima della prima ricarica. Limita la passività permanente e spinge l'uso — che è ciò che serve al circuito | P0-1, P2-4, comunicazione |
| **D5** | **Il pacchetto 480 € diventa facoltativo come da §9?** | Sì / No / Rivedere il prezzo | **Sì, senza eccezioni.** È l'unica struttura che regge il test dell'art. 6 lett. b) | Reclutamento |
| **D6** | **Si può pagare il pacchetto in Ky?** | Sì / No / Solo Ky acquistati, max 50% | **No, finché non c'è un parere scritto.** Il rischio (sostanziale + fiscale) è sproporzionato rispetto al beneficio | Materiali agente |
| **D7** | **Si introduce la commissione sulle transazioni (P0-3b)?** | Sì / No / Dopo il pilota | **Sì, entro 60 giorni.** È la risposta strutturale allo stress test e al test dell'art. 5 | Sprint 2 |
| **D8** | **Quale microarea di Reggio?** | — | Cammina 3 candidate col metodo §10.4 e decidi entro il giorno 7 | Tutto il piano operativo |
| **D9** | **Chi è l'avvocato e chi il commercialista?** | — | **Ingaggiali questa settimana**, in parallelo allo Sprint 0 — non in sequenza | Reclutamento, pacchetto 480 € |
| **D10** | **Si aggiungono `min_paying_clients` alle qualifiche?** | Sì / No | **Sì**, valori 2/4/8/8/8/8 come da `ANALISI_MLM_SOSTENIBILITA` §4.2 | Sprint 0 |
| **D11** | **Adotti la metrica principale a fasi (§16.1)?** | Sì / Resto sulla mia | **Sì.** La tua metrica resta il traguardo di fase 3, non il cruscotto del primo trimestre | Dashboard |
| **D12** | **Target agenti a 90 giorni: 3 inseriti / 2 attivi o 1 firmato / 0 attivi?** | — | **1 firmato, 0 attivi.** Il funnel selettivo non è comprimibile senza svuotarlo | Piano 90 gg |

---

# 23. Domande finali

Solo quelle le cui risposte cambiano materialmente la strategia o l'implementazione.

## Sul prodotto

1. **Qual è il catalogo KY Card reale in produzione oggi?** Nomi, prezzi, `bonus_type` e `bonus_value` per ciascuna, `mlm_points` e durata. Non posso leggere il DB di produzione, e il bonus del 25% è **per-card**: se i tagli reali hanno bonus diversi, tutta la comunicazione va ricalibrata e la tabella §7.1 va rifatta.

2. **Un cliente può riavere i suoi euro?** Nel codice non esiste alcun percorso di rimborso Ky→EUR per i clienti. È una scelta deliberata o una funzione mancante? La risposta cambia il pitch, le condizioni contrattuali e il diritto di recesso (14 gg per contratti a distanza).

3. **Quali sono le `transaction_fees` attive in produzione?** Devo poter rispondere "quanto mi costa" a un esercente con un numero, non con "verifico".

4. **Un esercente può convertire i Ky in euro?** Se la risposta è "no, mai", va detto in ogni primo incontro. Se è "sì, in futuro" o "sì, caso per caso", cambia tutto il pitch esercente e la struttura del rischio.

## Sul modello agenti

5. **Il "cassetto kmoney" (compensi in Ky) è una scelta definitiva o una fase transitoria?** `MLM_PROPOSAL.md` §1 diceva "guadagni in EUR reali, fuori dal circuito KY"; la modifica del 30/07 ha invertito la decisione. Ha impatto diretto sul BLOCCO 4 (autoconsumo) e sul trattamento fiscale.

6. **Qual è il rapporto autoconsumo/vendite esterne che ti aspetti?** Se gli agenti sono anche i principali clienti del circuito, il modello è strutturalmente esposto (Cons. Stato 321/2020). Serve un target esplicito — io suggerirei **max 20%** — e va misurato.

7. **Esiste già un contratto agente rivisto da un legale?** `mlm_agent_contract_signatures` esiste con snapshot firmatario e direttive, quindi un testo c'è. È stato validato da un professionista, o è stato scritto internamente?

8. **Enasarco: gli agenti sono incaricati alle vendite (art. 3 c. 3 L. 173/2005) o agenti di commercio (art. 3 c. 2)?** Cambia l'intero inquadramento previdenziale, la fatturazione e i costi per KMoney.

## Sul territorio

9. **Quanti esercenti e clienti ci sono OGGI su kmoney.it?** Numero reale, non stimato. Se ce ne sono già a Reggio, il piano parte dal giorno 30, non dal giorno 1. Se ce ne sono altrove, servono per la prova sociale nei pitch.

10. **Esiste già una lista di esercenti caldi a Reggio?** Contatti tuoi, di famiglia, ex clienti. È il fattore più predittivo dei primi 90 giorni (peso 20% nel metodo §10.4) e nessuno può fornirmelo al posto tuo.

11. **Quante ore alla settimana puoi dedicarci realmente?** Lo scenario realistico presuppone 20 h. Con 10 h vale lo scenario prudente, con 35 h l'ambizioso. Cambia tutti i target.

## Su tempi e vincoli

12. **C'è una data di lancio già comunicata a qualcuno?** Se sì, e cade prima del completamento dello Sprint 0, va spostata o va ridotto lo scopo del lancio (es. solo clienti, nessun reclutamento agenti).

13. **Chi altro può scrivere codice su questo progetto?** Le stime del backlog (~35-40 giorni-uomo per P0+P1) presuppongono una persona. Con due, gli sprint 1 e 2 si comprimono a ~4 settimane.

---

# Appendice — Fonti esterne

**Satispay**
- [Fondazione Bullone — intervista Alberto Dalmasso, 7/09/2021](https://bullone.org/2021/09/07/do-it-smart-con-satispay-si-racconta-alberto-dalmasso/)
- [Fintech Leaders — Alberto Dalmasso, CEO of Satispay](https://fintechleaders.substack.com/p/alberto-dalmasso-ceo-of-satispay)
- [ANSA — Satispay approda sui POS BCC, 21/06/2016](https://www.ansa.it/sito/notizie/economia/2016/06/21/banche-satispay-approda-sui-pos-bcc_0efe02e4-0462-40e7-bc4d-3a81ec89a46f.html)
- [ictBusiness — 30 nuove assunzioni e city manager, 10/03/2017](https://www.ictbusiness.it/cont/news/il-mobile-payment-spinge-satispay-30-nuove-assunzioni/38954/1.html)
- [ADC Group — campagna "La prima volta non si scorda mai", 2016-17](https://www.adcgroup.it/adv-express/creative-portfolio/out-home/la-prima-volta-non-si-scorda-mai-lo-dicono-satispay-e.html)
- [Ravenna e Dintorni — 1 maggiorenne su 4 usa Satispay, 18/07/2022](https://www.ravennaedintorni.it/economia/2022/07/18/dati-uso-satispay-ravenna-come-funziona-app-pagamenti-telefono/)
- [Quotidiano Piemontese — Satispay Smart Award 2020](https://www.quotidianopiemontese.it/2020/10/20/satispay-smart-award-2020-tutti-piemontsi-i-primi-10-negozi-piu-smart-ditalia/)
- [Satispay Help Center — Kit promozionale](https://support.satispay.com/it/articles/kit-promozionale) · [QR Code Business](https://support.satispay.com/it/articles/qr-code-business)
- [Satispay — Invita un amico](https://www.satispay.com/it-it/blog/guide-satispay/satispay-invita-un-amico-codice-promo-bonus/) · [Condizioni Community Bonus (PDF)](https://www.datocms-assets.com/133951/1766503577-community_bonus_it_4-4.pdf)
- [Satispay Business — Cashback](https://www.satispay.com/it-it/business/cashback/)
- [MilanoToday — Satispay ed Esselunga](https://www.milanotoday.it/economia/satispay-esselunga.html)
- [Punto Informatico — 800 mila iscritti, 26/09/2019](https://www.punto-informatico.it/satispay-800-mila-iscritti/)
- [Wikipedia IT — Satispay](https://it.wikipedia.org/wiki/Satispay) · [EconomyUp — la storia](https://www.economyup.it/fintech/satispay-storia-e-protagonisti-di-una-fintech-italiana-diventata-unicorno/)

**Normativa**
- [L. 173/2005 — Normattiva (vigente)](https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:legge:2005-08-17;173!vig=) · [Parlamento.it](https://www.parlamento.it/parlam/leggi/05173l.htm)
- [DPR 600/1973 art. 25-bis — Normattiva](https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.del.presidente.della.repubblica:1973-09-29;600!vig=)
- [D.lgs. 11/2010 (PSD2 IT) — Normattiva](https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2010-01-27;11!vig=)
- [TUB d.lgs. 385/1993 — Normattiva](https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:1993-09-01;385!vig=)
- [D.lgs. 231/2007 (AML) — Normattiva](https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2007-11-21;231!vig=)
- [Codice del Consumo d.lgs. 206/2005 — Normattiva](https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2005-09-06;206!vig=)
- [Buoni corrispettivo: artt. 6-bis](https://www.brocardi.it/testo-unico-iva/titolo-i/art6bis.html) · [6-ter](https://www.brocardi.it/testo-unico-iva/titolo-i/art6ter.html) · [6-quater DPR 633/72](https://www.brocardi.it/testo-unico-iva/titolo-i/art6quater.html)
- [Provvedimento Banca d'Italia 11/10/2018 — GU](https://www.gazzettaufficiale.it/eli/id/2018/10/29/18A06919/sg) · [FAQ (PDF)](https://www.bancaditalia.it/compiti/sispaga-mercati/strumenti-pagamento/normativa/FAQ_provvedimento_11_ottobre_2018.pdf) · [Modifica 22/04/2022](https://www.bancaditalia.it/media/notizia/modifica-del-provvedimento-della-banca-d-italia-dell-11-ottobre-2018/)
- [EBA/GL/2022/02 — Limited network exclusion](https://www.eba.europa.eu/publications-and-media/press-releases/eba-publishes-final-guidelines-limited-network-exclusion)

**Precedenti AGCM e giurisprudenza**
- [AGCM provv. 30423 — PS12323 ARIIX/NEWAGE, 13/12/2022, 960.000 € (PDF)](https://www.agcm.it/dotcmsCustom/tc/2028/1/getDominoAttach?urlStr=81.126.91.44:8080/C12560D000291394/0/173E46DE07B15242C125892B00564A95/$File/p30423.pdf)
- [AGCM — Lyoness PS11086, 19/12/2018, 3.200.000 €](https://www.agcm.it/media/comunicati-stampa/2019/1/Vendita-piramidale-e-promozione-ingannevole-sanzione-da-oltre-3-milioni-a-Lyoness)
- [AGCM — OneCoin PS10550, 10/08/2017, 2.595.000 €](https://www.agcm.it/media/comunicati-stampa/2017/8/alias-8889)
- [Consiglio di Stato VI, 13/01/2020 n. 321 — autoconsumo e reclutamento](https://www.giustizia-amministrativa.it/en/-/vendite-piramidali-e-violazione-della-concorrenza)

---

*Documento redatto il 14 agosto 2026. Nessuna modifica è stata apportata al codice, nessuna migration è stata eseguita. Il file `KMoney_Modello_Economico.xlsx` contiene il modello economico con formule modificabili.*
