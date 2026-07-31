# CHANGELOG_AI.md — Modifiche effettuate dalle AI

Ogni sessione AI che modifica il codice DEVE aggiungere una voce in cima a questo file.

Formato voce:

```
## YYYY-MM-DD — Titolo breve
- Cosa: descrizione della modifica
- File toccati: elenco
- Perché: motivazione
- DB: migration locale + SQL produzione (se applicabile)
```

---

## 2026-07-31 (2) — Contratto agente bloccante, prima del contratto generale KMoney
- Cosa: un utente in stato "in attesa di firma contratto agente" (`mlmAgentAwaitingContract()` — richiesta approvata, non ancora firmato) ora viene rediretto obbligatoriamente su `/mlm/contratto-agente` da QUALSIASI altra pagina del portale, incluso il contratto di adesione generale KMoney (che restava raggiungibile per primo dal banner in dashboard). Nuovo middleware `agent.contract`, applicato nello stack portale prima di `contract` (generale). A differenza del contratto generale non è possibile posticipare: è bloccante fino alla firma OTP, come richiesto. Si applica sia ai nuovi agenti registrati da uno sponsor sia al percorso classico "richiedi di diventare agente" — stessa condizione (`mlmAgentAwaitingContract()`) in entrambi i casi. Nessun impatto se il programma agenti (MLM) è disattivato (kmoney.it): il middleware si disattiva insieme al resto (evita un redirect verso una route che altrimenti risponderebbe 404).
- File toccati: `app/Http/Middleware/EnsureMlmAgentContractSigned.php` (nuovo), `bootstrap/app.php` (alias `agent.contract`), `routes/web.php` (aggiunto `agent.contract` allo stack portale, prima di `contract`, in entrambi i gruppi che lo usano), `resources/views/portal/mlm/agent-contract-sign.blade.php` (banner "prima di accedere al resto del portale devi firmare"), `tests/Feature/MlmAgentContractGateTest.php` (nuovo).
- Perché: richiesta esplicita di Laura dopo aver verificato il flusso — al primo login vedeva prima il contratto generale KMoney (dal banner in dashboard) e solo cliccando altrove trovava il contratto agente: "deve visualizzare prima il contratto agente bloccante finché non lo firma e solo dopo quello kmoney".
- Verificato in cloud: clone del repo, `php artisan route:list` (conferma `agent.contract` nello stack prima di `contract` su `/dashboard`), script diretto sul middleware (6 scenari: agente in attesa → redirect; stesso agente sulle route del contratto agente → nessun loop; cliente normale, agente già attivo, MLM disattivato, utente non autenticato → tutti passano). PHPUnit non eseguibile in questo sandbox (vedi nota storica: `composer install` con dev deps fallisce per rate-limit GitHub non autenticato), il test Feature aggiunto va confermato da Laura in locale.
- DB: nessuna migration.

## 2026-07-31 — Contratto di nomina agente già compilato dallo sponsor
- Cosa: quando un agente registra un nuovo agente sotto di sé (`/mlm/registra-agente`), ora raccoglie anche i dati anagrafici necessari al contratto di nomina — codice fiscale, data e luogo di nascita, indirizzo/CAP/comune/provincia di residenza (validati: maggiorenne, codice fiscale univoco a 16 caratteri). Il testo del contratto (`SystemSetting::defaultAgentContractText()`) è stato sostituito con il documento "Condizioni Generali per l'Incaricato di Vendita" (ex D. Lgs. 114/1998, testo fornito da Laura), con in testa una tabella "Dati del candidato Incaricato" compilata con questi dati + i dati dello sponsor. Il nuovo agente riceve via email le credenziali di primo accesso **più il PDF del contratto già compilato in allegato** (`MlmAgentCreatedByReferrerNotification`, generato con lo stesso renderer usato dalla pagina di firma — non ancora firmato, solo un'anteprima). Resta invariato: diventa agente attivo (mlm_role='agente') solo dopo aver firmato con OTP su `/mlm/contratto-agente` (`MlmAgentContractController`), che ora congela anche uno snapshot JSON strutturato dei dati del firmatario e dello sponsor (`mlm_agent_contract_signatures.signer_data_snapshot`) oltre allo snapshot HTML esistente.
- File toccati: `app/Http/Controllers/MlmPortalController.php` (validazione + storage nuovi campi + `buildAgentContractPreviewPdf()`), `app/Http/Controllers/MlmAgentContractController.php` (`signer_data_snapshot` alla firma), `app/Models/SystemSetting.php` (`renderAgentContractText()` con 10 nuovi placeholder, `defaultAgentContractText()` riscritto), `app/Models/User.php` (nuovi campi fillable/cast), `app/Models/MlmAgentContractSignature.php` (`signer_data_snapshot` fillable/cast), `app/Notifications/MlmAgentCreatedByReferrerNotification.php` (allegato PDF), `resources/views/portal/mlm/registra-agente.blade.php` (nuovi campi form), `resources/views/admin/contract-settings.blade.php` (nuovi placeholder in UI), `tests/Feature/MlmAgentCreateByReferrerTest.php` (nuovo).
- Perché: richiesta esplicita di Laura — "quando un agente registra un agente sotto di lui, vorrei che inserisse ... tutti i dati utili al contratto, l'agente registrato deve ricevere per email e nella sua area riservata il contratto allegato già compilato da firmare con OTP prima di iniziare a lavorare come agente". Il documento Word allegato da Laura ("CONDIZIONI GENERALI PER INCARICATO knm.docx") è il testo legale ufficiale, specifico per gli agenti (confermato da Laura: "il modello word è solo per agenti", non per il contratto di adesione clienti che resta separato).
- Verificato in cloud: clone completo del repo, `composer install`, `php artisan migrate` + seed ruoli, script end-to-end (registrazione con dati completi → validazioni → email con PDF allegato reale → firma OTP → snapshot strutturato) tutti verdi su SQLite.
- DB: migration `2026_07_31_180000_add_agent_registration_fields_to_users_table.php` (users: birth_date, birth_place, residence_address/zip/city/province) + `2026_07_31_180100_add_signer_snapshot_to_mlm_agent_contract_signatures_table.php` (mlm_agent_contract_signatures: signer_data_snapshot JSON).
- SQL produzione (phpMyAdmin): vedi `migrazione_prod_2026-07-31_dati_contratto_agente.sql` alla radice del progetto (include anche la nota su come resettare un eventuale testo contratto agente già personalizzato in admin).

## 2026-06-10 — Sidebar a gruppi collassabili (accordion)
- Cosa: refactoring completo del nav portale da lista piatta a 6 gruppi accordion stile banking app (Fineco/Revolut). Gruppo attivo si apre automaticamente in base a `$activeNav`; stato open/close persistito in `localStorage` chiave `km-nav-groups`.
- Gruppi: **Panoramica** (sempre visibile: Home, Movimenti, Wallet, Richieste), **Paga** (Invia KY, Sonic, Codice, Rateizza, Programmati, Compensa), **Incassa** (QR, NFC, Sonic, Codice, Link, Kit merchant), **Carte & Conto** (Ricarica KY, Card NFC, Fido, Sottoconti), **Circuito** (Directory, Shop, Annunci, Invita), **Strumenti** (Report, Webhook, API Token, Docs, Operatore, Assistenza).
- CSS: `.nav-group`, `.nav-group-btn`, `.nav-group-arrow`, `.nav-group-items` con transizione `max-height`/`opacity`.
- JS: `toggleGroup(btn)` + IIFE ripristino stato `localStorage` al caricamento pagina.
- File toccati: `resources/views/layouts/portal.blade.php`
- DB: nessuna migration

## 2026-06-10 — Sprint 3: kit merchant, referral, report
- Cosa:
  1. **Kit merchant** (`/kit-merchant`): nuova pagina hub con QR statico, link di pagamento, QR con importo, card NFC. PDF A5 stampabile scaricabile via dompdf (`/kit-merchant/qr-pdf`). Controller `MerchantKitController`, view `portal/merchant-kit.blade.php`, `pdf/merchant-qr.blade.php`.
  2. **Directory esercenti** — già presente (`/aziende`, `PortalController::companies()`), task verificato.
  3. **Referral** (`/invita`): migration `2026_06_10_100000_add_referral_fields_to_users_table.php` aggiunge `referral_code` (unique 12 char) e `referred_by_user_id` a `users`. Metodi `referralCode()`, `referralUrl()`, relazioni `referredBy()` / `referrals()` su `User`. `AuthController::register()` legge `?ref=CODE` e salva `referred_by_user_id`. View `portal/referral.blade.php` con stats e lista invitati. Campo hidden `ref` in `auth/register.blade.php`.
  4. **Report merchant** (`/report-merchant`): `MerchantReportController` con KPI (incassato, speso, cashback, fee, n° tx), trend 12 mesi, top 5 pagatori, tabella ultimi movimenti. Export CSV (`/report-merchant/export-csv`). Grafico con Chart.js CDN. View `portal/merchant-report.blade.php`.
- File toccati: `app/Http/Controllers/MerchantKitController.php` (nuovo), `app/Http/Controllers/ReferralController.php` (nuovo), `app/Http/Controllers/MerchantReportController.php` (nuovo), `app/Models/User.php`, `app/Http/Controllers/AuthController.php`, `routes/web.php`, `resources/views/portal/merchant-kit.blade.php` (nuova), `resources/views/portal/referral.blade.php` (nuova), `resources/views/portal/merchant-report.blade.php` (nuova), `resources/views/pdf/merchant-qr.blade.php` (nuova), `resources/views/auth/register.blade.php`
- DB: migration `2026_06_10_100000_add_referral_fields_to_users_table.php`
- SQL produzione (phpMyAdmin):
  ```sql
  ALTER TABLE `users`
    ADD COLUMN `referral_code` varchar(12) NULL AFTER `id`,
    ADD COLUMN `referred_by_user_id` bigint unsigned NULL AFTER `referral_code`,
    ADD UNIQUE KEY `users_referral_code_unique` (`referral_code`),
    ADD KEY `users_referred_by_user_id_foreign` (`referred_by_user_id`),
    ADD CONSTRAINT `users_referred_by_user_id_foreign` FOREIGN KEY (`referred_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
  ```

## 2026-06-10 — Sprint 2: hardening tecnico
- Cosa:
  1. **HMAC NFC**: aggiunto `\Log::warning()` sul fallback legacy 16-hex — ora ogni card vecchia genera un log per monitorarne il riciclo; firma nuova è già full 64-hex da `buildPayload()`
  2. **API v1 raw IDs rimossi**: `TransferController::formatTransfer()` ora espone `account_number` (KY number) invece di `from_account_id`/`to_account_id`; `AccountController::me()` rimuove `id` interno dal blocco `account`
  3. **Indici DB**: già presenti nella migration `2026_05_26_100000_add_mysql_performance_indexes.php` — nessuna modifica necessaria
  4. **PWA service worker**: aggiunto `/api/` e `/health/` a `BYPASS_PATTERNS`; versione cache bumped a `kmoney-v3` per forzare aggiornamento client
- File toccati: `app/Models/NfcCard.php`, `app/Http/Controllers/Api/V1/TransferController.php`, `app/Http/Controllers/Api/V1/AccountController.php`, `public/sw.js`
- DB: nessuna migration necessaria
- Perché: hardening tecnico Sprint 2 post-audit Codex

## 2026-06-10 — Analisi audit Codex + aggiornamento contesto AI
- Cosa: verificato su codice reale tutti i problemi segnalati dall'audit statico Codex; separato falsi positivi da problemi reali; aggiornato AI_CONTEXT.md con stato reale
- File toccati: AI_CONTEXT.md (solo contesto, nessuna modifica al codice)
- Perché: l'audit Codex era basato su analisi statica senza esecuzione — diversi problemi erano già risolti
- Risultato: Sprint 0 già completato; problemi reali rimasti sono minori (HMAC NFC, orWhereIn broker, API raw IDs, CSP)

## 2026-06-10 — Creazione file di contesto AI
- Cosa: creati `AI_CONTEXT.md`, `PROJECT_MAP.md`, `CHANGELOG_AI.md`, `AGENTS.md`
- File toccati: solo i 4 file nuovi, nessuna modifica al codice
- Perché: evitare la rianalisi completa del codebase ad ogni nuova sessione

## Storico precedente (ricostruito dalla memoria)

### Fix input KY → centesimi
- Cosa: introdotti helper `ky_to_cents()` e `ky_input()`; tutti i form del portale ora interpretano l'input utente come KY e lo convertono in centesimi (×100). L'API v1 è esclusa: riceve già centesimi.
- Perché: gli importi inseriti nei form venivano salvati senza conversione.

### Bug ky_format ×100
- Cosa: `ky_format()` ora divide per 100 (gli importi sono memorizzati in centesimi). Usare SEMPRE `ky_format()` per visualizzare importi KY.
- Perché: gli importi venivano mostrati 100 volte più grandi.

### Bug allineamento conti privati (import)
- Cosa: lo script di import agganciava i conti via `company_id`, che è NULL per i privati. Corretto usando `owner_user_id`.
- Perché: i saldi dei conti personali (KYP) risultavano disallineati dopo l'import.
