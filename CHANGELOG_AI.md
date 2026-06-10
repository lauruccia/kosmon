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
