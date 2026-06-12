# QA Mobile — Smoke Test Checklist

Eseguire su dispositivo reale (o DevTools 390px) prima di ogni deploy in produzione.
Testare su: **Chrome Android** + **Safari iOS** (minimo).

---

## Flusso 1 — Login

- [ ] La pagina di login si carica correttamente a 390px (nessun overflow orizzontale)
- [ ] Il campo email e password sono facilmente tappabili
- [ ] Il pulsante "Accedi" è visibile senza scroll dopo aver aperto la tastiera
- [ ] Login con email/password funziona e reindirizza alla dashboard
- [ ] La challenge 2FA si mostra e il campo OTP è correttamente autofocused
- [ ] Il pulsante "Usa passkey" è visibile e funziona su dispositivi compatibili

## Flusso 2 — Paga

- [ ] La voce "Paga" nella sidebar/nav è raggiungibile da mobile
- [ ] Il form di pagamento si carica correttamente a 390px
- [ ] I campi importo e descrizione sono usabili con la tastiera virtuale
- [ ] L'autocomplete del destinatario funziona (dropdown leggibile)
- [ ] Il riepilogo prima della conferma è leggibile (nessun testo troncato)
- [ ] Il pagamento va a buon fine e mostra la conferma
- [ ] Il pulsante "Indietro" dopo la conferma funziona correttamente

## Flusso 3 — Incassa QR

- [ ] La pagina Incassa QR si carica correttamente
- [ ] Il QR code è visualizzato e sufficientemente grande da essere scannerizzato
- [ ] Il campo importo opzionale è usabile
- [ ] Il pulsante "Condividi" / "Copia link" funziona su iOS e Android
- [ ] La pagina di accettazione QR da parte del pagante si carica correttamente
- [ ] Dopo la conferma, la notifica in-app arriva al ricevente

## Flusso 4 — Movimenti

- [ ] La lista movimenti si carica a 390px (righe leggibili, importi non troncati)
- [ ] Lo scroll verticale funziona senza blocchi
- [ ] I filtri (data, tipo) si aprono e applicano correttamente
- [ ] Il dettaglio di un movimento si apre e mostra tutte le informazioni
- [ ] La paginazione / caricamento successivo funziona

## Flusso 5 — Richieste di pagamento

- [ ] La sezione Richieste è raggiungibile da mobile
- [ ] Le richieste in entrata sono visibili e leggibili
- [ ] Il bottone "Conferma" / "Rifiuta" è tappabile (minimo 44px tap target)
- [ ] La conferma mostra un feedback visivo (successo/errore)
- [ ] Le richieste in uscita mostrano lo stato corretto

---

## Check generali (su tutti i flussi)

- [ ] Nessun overflow orizzontale a 390px
- [ ] La sidebar / nav mobile si apre e chiude correttamente
- [ ] Il banner PWA "Installa" appare su Chrome Android (seconda visita)
- [ ] Le istruzioni iOS per l'installazione appaiono su Safari iPhone (seconda visita)
- [ ] Le notifiche in-app sono leggibili nel pannello notifiche mobile
- [ ] Il footer/nav bottom non copre contenuto importante

---

## Test Playwright (automatizzati)

Configurazione minima in `playwright.config.js` per replicare i check sopra:

```js
// playwright.config.js
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  use: { baseURL: 'http://localhost:8000' },
  projects: [
    { name: 'mobile-chrome',  use: { ...devices['Pixel 5'] } },
    { name: 'mobile-safari',  use: { ...devices['iPhone 12'] } },
  ],
});
```

File di test di riferimento: `tests/e2e/mobile-smoke.spec.js`
Eseguire con: `npx playwright test --project=mobile-chrome`

---

## Note

- Gli screenshot di regressione sono salvati in `tests/e2e/screenshots/`
- In caso di fix scroll/sidebar, aggiungere un test specifico per il componente
- Aggiornare questa checklist ad ogni nuovo flusso aggiunto al portale
