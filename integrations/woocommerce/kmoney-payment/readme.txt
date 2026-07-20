=== KMoney Payment Gateway ===
Contributors: kmoney
Tags: woocommerce, payment gateway, kmoney
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 2.2.0
License: GPLv2 or later

Accetta pagamenti in KY (KMoney) su WooCommerce tramite checkout hosted sicuro: redirect + webhook,
percentuale KY per negozio/categoria/prodotto con pagamento misto KY + euro, nessuna credenziale
KMoney del cliente gestita da questo sito.

== Description ==

Questo plugin sostituisce il vecchio plugin "KMoney" (che raccoglieva email e password KMoney del
cliente direttamente nel checkout WooCommerce e le inviava a un'API legacy separata su
kosmomoney.com). Questo modulo non fa mai nulla del genere:

1. Alla conferma ordine, crea una richiesta di pagamento sull'API ufficiale KMoney (kmoney-app),
   server-to-server, con il token del negoziante — per la sola quota KY dell'ordine, calcolata
   dalla % impostata dal negoziante (globale, per categoria o per prodotto).
2. Reindirizza il cliente sulla pagina di pagamento ospitata da KMoney, dove si autentica con le
   proprie credenziali (2FA / passkey inclusi) e conferma l'importo.
3. Riceve conferma del pagamento in due modi indipendenti: un webhook autorevole
   (payment_request.paid) e una verifica server-side quando il cliente torna sul sito. Nessuno dei
   due si fida dei soli parametri nell'URL di ritorno.
4. Sugli ordini misti (quota KY < 100%) il totale scende al saldo in euro e il cliente lo paga
   subito dopo con qualunque altro metodo (pulsante in pagina di ringraziamento + email con link).

Regola del circuito: se il conto KMoney del negoziante è in negativo, tutto viene venduto al 100%
in KY e le percentuali non sono modificabili finché il saldo non torna positivo. Sulla pagina
prodotto viene mostrato il badge "Pagabile al X% in KMoney" con il link "Registrati su KMoney".

Vedi KMONEY_WOOCOMMERCE_INTEGRATION.md per la guida completa (installazione, configurazione,
riferimento API, checklist di test).

== Installation ==

1. Disattiva ed elimina il vecchio plugin "KMoney" (cartella kmoney/), se presente.
2. Carica la cartella kmoney-payment/ in wp-content/plugins/.
3. Attiva "KMoney Payment Gateway" da Plugin.
4. In WooCommerce > Impostazioni > Pagamenti > KMoney inserisci il NUMERO DI CONTO KMoney del
   negozio (es. KYB seguito da 13 caratteri) e salva: la richiesta di collegamento viene inviata
   all'amministratore del circuito e, appena approvata, token API e webhook si configurano da soli
   (ricarica la pagina per verificare). In alternativa resta possibile la configurazione manuale
   con token API e secret webhook.
5. La pagina mostra sempre lo stato della connessione API e del conto (incluso l'avviso
   "conto in negativo → 100% forzato").
6. (Facoltativo) Imposta la % KMoney per categoria (Prodotti > Categorie) o per singolo prodotto
   (Dati prodotto > Generale).

== Changelog ==

= 2.2.0 =
* Collegamento del conto con il SOLO numero di conto: il negoziante inserisce il KYB e salva;
  l'admin del circuito approva da KMoney e il plugin ritira e salva da solo token API e secret
  webhook (consegna una tantum, protetta da claim secret). Niente più copia-incolla.
* URL base API precompilato con https://kmoney.it/api/v1.
* Validazione del numero di conto (formato KYB/KYP + 13 caratteri) nelle impostazioni.

= 2.1.0 =
* Percentuale KY configurabile: globale, per categoria (vince la più alta) o per singolo prodotto.
* Pagamento misto: quota KY su KMoney, saldo in euro con qualunque altro gateway sullo stesso ordine.
* Regola conto in negativo: 100% KY forzato ovunque, percentuali bloccate anche in admin.
* Badge "Pagabile al X% in KMoney" sulla pagina prodotto + link "Registrati su KMoney" (anche al checkout).
* Pannello di stato conto/connessione nella pagina impostazioni; spedizione e costi extra seguono la % globale.

= 2.0.0 =
* Riscrittura completa: checkout hosted via redirect + webhook, nessuna credenziale cliente gestita
  dal sito. Sostituisce il vecchio flusso che chiedeva email/password KMoney nel checkout.
