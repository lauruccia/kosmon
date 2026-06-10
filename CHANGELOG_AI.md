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
