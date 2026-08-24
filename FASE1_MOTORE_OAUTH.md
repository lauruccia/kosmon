# Fase 1 — quale motore OAuth: Laravel Passport o fatto in casa

Documento di decisione, 24/08/2026. Non è ancora stato scritto codice.
Riferimento: `PIANO_SHOP_ESTERNO.md` §4 (Identità: "Accedi con KMoney").

---

## In una riga

**Consiglio il motore fatto in casa.** Non perché Passport sia scritto male —
è il contrario — ma perché in questa produzione *installare una libreria nuova
costa molto più che scrivere il codice*, e il conto va pagato due volte
(kmoney.it e kosmopay.it) a mano, senza terminale, con il sito acceso.

---

## 1. Il vincolo che decide tutto

Non è una questione di gusti: è come deployate oggi.

- `vendor/` **non è nel repo** (è in `.gitignore`, riga `/vendor`).
- `.cpanel.yml` **non esegue `composer install`** — c'è scritto a chiare lettere
  nel commento in cima al file: *"lento/instabile su hosting condiviso… Se in
  futuro cambi le dipendenze, aggiorna vendor/ a parte una tantum"*.
- Su **kmoney.it non hai né Terminale né SSH**: solo Gestione File e phpMyAdmin.
- Su **kmoney.it la cartella del checkout git *è* la cartella viva**: quando fai
  "Update from Remote" il PHP nuovo è online nello stesso istante. Non esiste
  una fase di preparazione in cui il codice c'è ma non è ancora attivo.

Tradotto: ogni riga di codice che scriviamo viaggia gratis, ogni libreria nuova
viaggia a mano. È il motivo per cui negli ultimi mesi i deploy sono stati
indolori — sono sempre stati "solo codice".

---

## 2. Cosa costa davvero Passport

### 2.1 Le dipendenze che mancano (verificate nel tuo `vendor/`, non stimate)

`laravel/passport` 13.x chiede: `league/oauth2-server ^9.2`, `firebase/php-jwt`,
`phpseclib/phpseclib ^3.0`, `php-http/discovery`, `symfony/psr-http-message-bridge`,
`psr/http-factory-implementation`. A sua volta `league/oauth2-server` chiede
`league/event ^3.0`, `league/uri ^7.8`, `lcobucci/jwt ^5.6`,
`defuse/php-encryption ^2.4`, `psr/http-server-middleware`, `psr/clock`.

Ho controllato cosa hai già:

| Già presente ✅ | Da aggiungere ❌ |
|---|---|
| `league/uri` 7.8.1, `league/uri-interfaces` | `laravel/passport` |
| `nyholm/psr7` 1.8.2 (soddisfa `psr/http-factory-implementation`) | `league/oauth2-server` |
| `symfony/psr-http-message-bridge` v7.4.8 | `league/event` |
| `psr/clock` 1.0.0, `psr/http-message`, `psr/http-factory` | `lcobucci/jwt` (hai solo `lcobucci/clock`) |
| `symfony/console`, tutti gli `illuminate/*` | `defuse/php-encryption` |
| `paragonie/*` (serve a phpseclib) | `psr/http-server-middleware` (+ `-handler`) |
| | `firebase/php-jwt` |
| | `php-http/discovery` |
| | `phpseclib/phpseclib` |

**Nove pacchetti nuovi.** Metà del lavoro era già fatto (Passport è a metà strada
grazie a Reverb e Stripe che si sono portati dietro PSR-7), ma i pezzi centrali
del motore OAuth mancano tutti.

### 2.2 Perché non basta copiare nove cartelle

`vendor/` non è solo cartelle: è anche `vendor/composer/autoload_static.php`,
`autoload_psr4.php`, `autoload_classmap.php`, `installed.json`, `installed.php`.
Sono generati e devono corrispondere **all'insieme intero**, non ai soli nuovi
arrivati. Se sul server carichi i mappari nuovi ma un altro pacchetto lì è a una
versione diversa dalla tua, la mappa punta a file che su quel server non
esistono → errore fatale, e non nel punto che hai toccato.

L'unica via davvero sicura è quindi: **rendere il `vendor/` dei server identico
al tuo**, cioè caricarlo tutto. Che significa, di fatto, aggiornare in un colpo
solo *tutte* le librerie del sito (Laravel, Stripe, Dompdf, web-auth…) su una
produzione che oggi funziona, senza staging e senza rollback automatico. Non è
impossibile — è la cosa più rischiosa che abbiamo fatto da quando ci lavoriamo.

### 2.3 Le tabelle e le chiavi

Passport 13 crea **5 tabelle**: `oauth_clients`, `oauth_access_tokens`,
`oauth_refresh_tokens`, `oauth_auth_codes`, `oauth_device_codes`. Da scrivere in
SQL a mano, con le regole della fase 0b (niente `information_schema`, blocchi
uno alla volta, due dialetti diversi perché kmoney è MariaDB e kosmopay MySQL 8).

Poi servono le **chiavi RSA**: normalmente `php artisan passport:keys`, che su
cPanel non puoi lanciare. Aggirabile — si generano sul tuo PC e si mettono in
`PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` nel `.env` di ciascun server dopo
aver pubblicato la config di Passport — ma è un passo in più, diverso per sito,
e se una chiave manca o è troncata nel copia-incolla i token non si emettono più.

Infine il client kshop va inserito a mano in `oauth_clients` (INSERT con id UUID
e secret) su entrambi i server.

### 2.4 L'ordine di deploy diventa a tre tempi

La fase 0b ci ha insegnato "prima l'SQL, poi il codice". Con Passport diventa:

> **1. vendor → 2. SQL → 3. codice**

e l'errore in fase 1 è più grave di quello che hai già visto. Il 24/08 il codice
prima dell'SQL ha rotto *lo shop* (500 sull'acquisto, resto del sito in piedi).
Qui il codice prima del vendor rompe **tutto il sito**: `config/auth.php` con il
guard `passport` e i riferimenti nel `AppServiceProvider` vengono letti a ogni
richiesta, e una classe che non esiste è un errore fatale al boot. Su kmoney.it,
dove il pull è già la produzione, la finestra fra l'errore e la sua scoperta è
il tempo che ci metti ad aprire il sito.

---

## 3. Cosa costa il motore fatto in casa

### 3.1 Cosa scriviamo davvero

Due tabelle:

```
oauth_authorization_codes   code_hash(unique), client_id, user_id, scopes,
                            redirect_uri, code_challenge, code_challenge_method,
                            expires_at, consumed_at, created_ip

oauth_access_tokens         uuid, token_hash(unique), refresh_hash(unique),
                            client_id, user_id, scopes, expires_at,
                            refresh_expires_at, revoked_at, last_used_at, created_ip
```

Nessuna tabella `oauth_clients`: il client è **uno solo** (kshop) e vive in
`config/oauth.php`, con id e secret presi dal `.env`. Meno tabelle, meno SQL di
produzione, e la revoca di un client è una modifica di `.env` — non un UPDATE.

Endpoint, tutti con i nomi standard così kshop può usare una libreria client
normale:

| Rotta | Cosa fa |
|---|---|
| `GET /oauth/authorize` | pagina di consenso, dietro `auth`,`verified`,`twofactor`,`not.suspended`,`contract` |
| `POST /oauth/authorize` | l'utente accetta → codice monouso, redirect a kshop |
| `POST /oauth/token` | scambio codice→token (con PKCE) e rinnovo |
| `POST /oauth/token/revoke` | revoca |
| `GET /api/v1/userinfo` | chi sei: utente, azienda, conto, ruolo, stato commerciale |

Più un middleware `oauth.token` con controllo degli scope, sul modello di
`ApiTokenAuth` che già esiste e funziona (stessa idea: token in chiaro solo dal
client, in DB solo l'hash SHA-256).

**Stima: ~700 righe di codice più ~400 di test.** Per confronto, la fase 0a ne ha
prodotte 19 di test e la 0b 10; qui i test da scrivere sono più di venti perché
ogni regola di sicurezza vuole il suo.

### 3.2 Le regole di sicurezza che vanno coperte una per una

Questo è il vero prezzo dell'opzione fatta in casa, e lo scrivo per intero
perché tu possa tenermelo davanti quando consegno:

1. PKCE `S256` **obbligatorio** (niente `plain`, niente PKCE assente)
2. il codice è **monouso**: consumato dentro una transazione con lock
3. il codice scade in **60 secondi**
4. `redirect_uri` confrontata **per intero** con la whitelist (niente prefissi:
   è da lì che nascono gli open redirect)
5. il secret del client verificato con `hash_equals` (niente `==`)
6. `state` restituito identico, sempre
7. scope validati contro la lista chiusa (`profile`, `account.read`,
   `orders.write`, `mandate`)
8. token **solo in hash** nel DB, mai in chiaro, mai nei log
9. token scaduto e token revocato → 401, con messaggi distinti solo internamente
10. refresh token a rotazione: usarne uno vecchio revoca l'intera catena
11. il consenso **non** si può saltare la prima volta, e la pagina è dietro tutta
    la catena di middleware esistente (azienda sospesa o senza contratto = niente
    accesso a kshop, come da piano)
12. rate limit sugli endpoint, e ogni emissione/revoca finisce in `AuditLog`

Sono dodici regole note e verificabili con test: è codice di sicurezza, ma è
codice di sicurezza *piccolo e chiuso*, non un pezzo di crittografia inventato.
Non scriviamo un algoritmo: scriviamo dei controlli.

### 3.3 Cosa NON facciamo (ed è il motivo per cui bastano 700 righe)

Passport è grande perché copre casi che a te non servono: client credentials,
password grant, device flow, personal access token, registrazione dinamica di
client terzi, gestione client da UI, JWT firmati con RSA e rotazione chiavi.

A noi serve **un flusso e un client**. E i JWT firmati non servono proprio: il
token lo verifica KMoney rispondendo a `/userinfo`, quindi un token opaco è più
sicuro (revocabile all'istante) e più semplice di uno firmato.

---

## 4. Il confronto, affiancato

| | Passport | Fatto in casa |
|---|---|---|
| Codice da scrivere e mantenere | poco (config + viste) | ~700 righe + ~400 di test |
| Pacchetti nuovi in `vendor/` | **9** | 0 |
| Deploy su cPanel | vendor a mano su 2 server, senza SSH | **come oggi: solo codice** |
| Tabelle nuove in produzione | 5 | 2 |
| Chiavi/segreti da installare a mano | chiavi RSA per sito + INSERT client | una riga di `.env` per sito |
| Se sbagli l'ordine di deploy | **sito intero giù** | come oggi: 500 solo sulle rotte nuove |
| Rischio di sicurezza | basso (libreria collaudata) | **medio: dipende dai nostri test** |
| Manutenzione futura | aggiornamenti di sicurezza = di nuovo vendor a mano | nostra, ma sotto controllo |
| Se un giorno servono client terzi | già pronto | va aggiunta una tabella `clients` |

---

## 5. La scelta è reversibile (e questo conta più di tutto)

Gli endpoint del fatto in casa hanno **gli stessi nomi e le stesse risposte** di
un server OAuth2 standard. Quindi kshop, dall'altra parte, userà comunque una
libreria client normale (`league/oauth2-client` o l'equivalente) e **non sa né
gli importa** quale motore ci sia dietro.

Se fra un anno vorrai aprire l'SSO a plugin di terze parti, o non vorrai più
mantenere questo codice, passare a Passport significa riscrivere quelle 700
righe *senza toccare kshop* e senza cambiare l'esperienza dell'utente. La
decisione di oggi non ti incastra: rimanda soltanto il conto del vendor a un
giorno in cui, magari, avrai un hosting con SSH.

Il contrario non è vero allo stesso modo: partire da Passport significa pagare
il vendor adesso, e ogni volta che esce un aggiornamento di sicurezza.

---

## 6. Una terza via, che ho guardato e scartato

Estendere l'`ApiToken` esistente con una specie di "token di scambio" fatto in
casa, come già succede per il pairing e-commerce (`EcommercePairingController`,
con `claim_secret`). Zero tabelle nuove.

Scartata per un motivo di sostanza, non di eleganza: `ApiToken` è un token
**dell'azienda**, emesso una volta e usato da un server. Qui invece serve la
delega di **un utente** che, in quel momento, dice sì. Sono due cose diverse, e
piegando la prima verso la seconda finiremmo per riscrivere OAuth con nomi
nostri — cioè l'opzione 2, ma senza il vantaggio di parlare una lingua che le
librerie client capiscono già.

---

## 7. Due cose emerse dal codice, che valgono comunque

Le segnalo perché toccano la fase 1 qualunque motore scegli.

1. **`users` non ha una colonna `uuid`.** Il piano (§4) dice che il token deve
   portare "uuid utente, uuid azienda": `companies` e `accounts` l'uuid ce
   l'hanno, `users` no — ha solo l'`id` numerico. Mettere l'id numerico dentro un
   token che viaggia verso un'altra applicazione è brutto (dice quanti utenti
   hai) e ci lega le mani. Propongo di aggiungere `users.uuid` nella stessa
   migrazione della fase 1: è additiva e non rompe niente.

2. **Lo step-up c'è già ed è a 15 minuti** (`RequireStepUp::STEP_UP_WINDOW_MINUTES`).
   Il mandato di pagamento della fase 2 può appoggiarcisi senza scrivere nulla di
   nuovo, come previsto dal piano.

---

## 8. Cosa faccio appena mi dici quale strada

In entrambi i casi la fase 1 esce così, come le 0a e 0b:

- migrazioni + codice + pagina di consenso, tutto additivo (in produzione non se
  ne accorge nessuno finché kshop non esiste);
- la suite di test, verificata con mutazioni deliberate come nelle fasi
  precedenti (rompo apposta il codice e controllo che cadano i test giusti);
- l'SQL di produzione già scritto con le regole della 0b, verificato su MariaDB
  vera prima di dartelo, in blocchi da eseguire uno alla volta;
- l'aggiornamento di `PIANO_SHOP_ESTERNO.md` §9.

Se scegli Passport, aggiungo una cosa: la procedura di caricamento del `vendor/`
passo per passo per Gestione File, **da provare prima su kosmopay.it**, con il
backup del vendor attuale da fare prima di iniziare.
