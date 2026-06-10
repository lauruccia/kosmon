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
