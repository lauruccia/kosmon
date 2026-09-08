# Lo shop KMoney con le funzioni di WoodMart 7.5.1

**Data:** 08/09/2026 · **Ambito:** shop interno (`/shop`, carrello, cassa, ordini, vendite)
**Origine:** tema `woodmart` 7.5.1 fornito da Laura, letto per intero (pannello opzioni, template WooCommerce, moduli).
**Metodo:** spoglio delle opzioni reali del tema (`inc/admin/settings/*.php`) e confronto con il codice vivo di KMoney. Nessuna riga toccata.

---

## 0. La cosa da dire prima di tutte le altre

**WoodMart non si installa sopra KMoney.** È un tema WordPress: vive dentro WooCommerce, parla di `wp_posts`, `wc_get_product()`, hook e widget. KMoney è Laravel, con partita doppia, mix KY/euro e multi-venditore sotto ogni prodotto. Non c'è nessun ponte, e non serve: quello che si prende da WoodMart è **l'elenco delle funzioni e il modo in cui sono disposte in pagina**, non il codice. Copiare i suoi file PHP/CSS dentro il portale sarebbe anche fuori licenza (la licenza ThemeForest copre l'uso del tema su un sito, non il riuso del sorgente in un altro prodotto). Le sue idee, invece, si guardano e si rifanno: quello è legittimo e conviene.

**La buona notizia: la base per farlo esiste da quattro giorni.** Il commit `1558b16` del 04/09 ha portato dentro `public/assets/css/shop.css` (628 righe), il carattere Inter caricato in locale e i componenti Blade condivisi (`x-shop.product-card`, `price`, `mix-badge`, `media`, `breadcrumb`, `empty`, `placeholder`) con 292 righe di test. Era la Fase 0 del piano del 02/09, quella che sembrava non produrre niente. È fatta. **Da qui in poi ogni funzione nuova si scrive una volta sola e appare ovunque** — ed è esattamente la condizione che rende sensato questo confronto.

---

## 1. WoodMart funzione per funzione, contro KMoney oggi

Le voci vengono dal pannello reale del tema, non da un elenco commerciale.

### 1.1 Catalogo

| Funzione WoodMart | KMoney oggi | Costo | Nota |
|---|---|---|---|
| Griglia/lista, colonne per desktop-tablet-mobile | griglia sola, fissa | S | il componente card c'è già: cambia solo il contenitore |
| Ordinamento (rilevanza, prezzo ↑↓, novità, più venduti) | ❌ fisso `featured, created_at` | S | "più venduti" chiede un contatore vendite su `listings` |
| Filtri per attributo, fascia di prezzo, marca | ❌ solo categoria/testo/azienda/%KY | M | gli attributi esistono ma servono solo a generare varianti |
| Filtri in colonna a scomparsa (off-canvas) su mobile | ❌ | S | |
| **AJAX shop**: filtri e paginazione senza ricaricare | ❌ | M | |
| "Carica altri" / scorrimento infinito | ❌ paginazione a 15 | S | |
| Etichette Novità / Hot / Saldo / Esaurito | parziale (offerta e "in evidenza") | S | vedi nodo 4 sul "-30%" con mix KY |
| Barra di avanzamento scorte ("ne restano 3") | ❌ | S | dato già in `stock_quantity` |
| Anteprima rapida (quick view) in finestra | ❌ | M | |
| Aggiungi al carrello dalla griglia, senza aprire il prodotto | ❌ | S | |
| Pastiglie colore/immagine delle varianti già nella griglia | ❌ (le pastiglie ci sono solo dentro il prodotto) | M | |
| Seconda immagine al passaggio del mouse | ❌ | S | le tre misure immagine esistono già |
| Briciole di pane | ✅ componente già scritto | — | da montare dove manca |
| Scheletri di caricamento | ❌ | S | |

### 1.2 Scheda prodotto

| Funzione WoodMart | KMoney oggi | Costo | Nota |
|---|---|---|---|
| Galleria con miniature a lato/sotto, zoom, lightbox | lightbox ✅, zoom ❌, miniature ❌ | S | max 6 immagini |
| Linguette descrizione / caratteristiche / spedizione / resi | ❌ tutto in colonna | S | |
| **Barra d'acquisto appiccicata** in basso su mobile | ❌ | S | fra le cose che spostano di più le conversioni |
| Correlati e "chi ha comprato questo" come carosello | elenco testuale | S | |
| **Comprati spesso insieme** (bundle con sconto) | ❌ | L | tocca il calcolo mix KY/euro: vedi nodo 1 |
| Recensioni con stelle | ❌ nessuna tabella | M | decisioni già prese il 26/08 |
| Guida alle taglie (finestra per categoria) | ❌ | S | |
| Marca/brand con pagina e logo | ❌ | M | l'azienda venditrice non è la marca |
| Conto alla rovescia sull'offerta | offerta ✅, contatore ❌ | S | `listing_offers` c'è già |
| Condivisione social + Open Graph | ❌ | S | serve prima lo slug (fase vetrina) |
| Vista 360°, video in galleria | ❌ | L | lusso vero, ultimo della lista |
| Avvisami quando torna disponibile | ❌ | M | |

### 1.3 Carrello, cassa, account

| Funzione WoodMart | KMoney oggi | Costo | Nota |
|---|---|---|---|
| Mini-carrello a scomparsa | ✅ e funziona anche senza JavaScript | — | meglio di WoodMart su questo |
| Quantità modificabile nel carrello senza ricaricare | ❌ | S | |
| Cassa in passi con indicatore grafico | 3 passi ✅, indicatore ❌ | S | |
| Rubrica indirizzi | ✅ fino a 10 | — | WooCommerce base non ce l'ha |
| Coupon e codici sconto | ❌ | M | **bloccato dal nodo 1** |
| Spedizione gratis sopra soglia, zone, tariffe | ❌ costo fisso per prodotto | L | **bloccato dal nodo 2** |
| Preferiti (anche liste multiple) | ❌ | M | |
| Confronta prodotti | ❌ | M | utile con cataloghi omogenei, meno da noi |
| Visti di recente | ❌ | S | |
| Ricerca istantanea a tendina con immagini | ❌ `LIKE %q%` | M | senza indice full-text va lenta oltre i mille prodotti |
| Pagina "grazie" personalizzabile | ✅ | — | |
| Tracciamento ordine, stati, resi | ✅ ciclo completo + resi a 14 giorni | — | **più completo di WooCommerce base** |

### 1.4 Cose di WoodMart che a KMoney non servono

- **"Login per vedere i prezzi" / modalità catalogo** — da noi tutto lo shop è già dietro `auth`. Semmai il problema è l'opposto: il catalogo non è condivisibile né indicizzabile (fase vetrina).
- **Costruttore di header, mega-menu, portfolio, blocchi Elementor** — sono l'80% del peso del tema e riguardano il sito vetrina WordPress, non un portale applicativo.
- **Multi-venditore Dokan/WCFM** — WoodMart ci si integra; noi il multi-venditore ce l'abbiamo nativo e più profondo (un ordine per venditore, lock per conto, snapshot per riga).

---

## 2. Le sei che valgono di più

Se si guarda il rapporto fra quanto cambia la sensazione del negozio e quanto costa scriverle, queste sei stanno tutte davanti:

1. **Ordinamento del catalogo.** È la mancanza che si nota per prima: oggi non si può nemmeno vedere il prodotto più economico.
2. **Barra d'acquisto appiccicata su mobile.** Mezza giornata, e il bottone che porta i soldi smette di sparire mentre si legge.
3. **Filtri per attributo e prezzo, con off-canvas su mobile.** È ciò che fa sembrare "un negozio vero" più di ogni effetto grafico.
4. **Anteprima rapida + aggiungi al carrello dalla griglia.** Toglie due clic a ogni acquisto.
5. **Recensioni con stelle.** L'unico blocco di fiducia che oggi manca del tutto, e con la regola già decisa (scrive solo chi ha un ordine `delivered`) non diventa un canale di spam.
6. **Etichette e conto alla rovescia sulle offerte.** I dati esistono già in `listing_offers`: è quasi solo presentazione.

Nessuna delle sei tocca il motore finanziario. Tutte e sei si appoggiano ai componenti del 04/09.

---

## 3. Le quattro che non si copiano senza decidere prima

WoodMart le dà per scontate perché WooCommerce ha una moneta sola. Noi ne abbiamo due.

1. **Sconto e coupon.** Uno sconto del 10% su un prodotto 75% KY: sconta i KY, gli euro, o tutti e due in proporzione? Se sconta solo i KY il venditore incassa gli stessi euro e ci rimette circuito — probabilmente è quello che vuoi, ma è una scelta di modello economico. *(Già chiesto il 02/09, ancora senza risposta.)*
2. **Spedizione gratis sopra soglia.** Soglia su che cosa — totale KY, totale euro, somma? E per venditore o per carrello, visto che l'ordine si spacca per venditore?
3. **Etichetta "-30%".** Percentuale su quale delle due quote? Se il prezzo pieno è 100 KY al 75% e l'offerta è 70 KY, il compratore vede scendere sia i KY sia gli euro, ma non nella stessa proporzione se cambia anche il mix.
4. **IVA e documento.** La quota euro non passa dal circuito: la fattura di quella parte è del venditore. Possiamo produrre un **riepilogo d'ordine** con le due P.IVA e l'imponibile diviso per quota — ma chiamarlo fattura lo decide il commercialista, non io.

---

## 4. Come lo spezzerei

Le stime sono giornate piene, con test come nelle fasi precedenti. La Fase 0 non compare perché è chiusa.

| Blocco | Contenuto | Giorni |
|---|---|---|
| **A — Il catalogo si comporta da negozio** | ordinamento, filtri attributo+prezzo, off-canvas mobile, "carica altri", etichette, barra scorte, seconda immagine al passaggio, scheletri | 4-5 |
| **B — La scheda prodotto convince** | miniature+zoom, linguette, barra appiccicata mobile, correlati a carosello, conto alla rovescia, guida taglie | 3-4 |
| **C — Fiducia** | recensioni con stelle, media in card, filtro 4+, moderazione admin | 2-3 |
| **D — Meno attriti** | anteprima rapida, carrello dalla griglia, quantità senza ricaricare, visti di recente, preferiti, ricerca a tendina | 4-5 |
| **E — Leve commerciali** | coupon, soglia spedizione, cross-sell e "comprati insieme", contatore vendite | 4-5 · *dopo i nodi 1 e 2* |
| **F — Retro del negozio** | spedizione per zone/soglie, scorta bassa, import CSV, prodotti digitali | 4-5 |
| **G — Vetrina e SEO** | slug, meta, Open Graph, catalogo senza login | 3-4 · già pianificato a parte |

**A + B + C = 9-12 giornate**, e il negozio sembra un tema premium. Il resto aggiunge funzioni, non impressione.

---

## 5. Cosa mi serve da te

1. Le due decisioni ferme dal 02/09: **sconto sui KY o sugli euro**, e **soglia spedizione su cosa**.
2. Le **recensioni** le vuoi in questo giro o dopo? Sono l'unico blocco che richiede una tabella nuova fra i primi.
3. Il **confronta prodotti** lo lascio fuori salvo tua richiesta: con un catalogo eterogeneo confronta mele e biciclette.
4. Del tema WoodMart ti interessa anche **l'aspetto** (spaziature, ombre, tipografia, colore dell'acquisto) o solo le funzioni? Se sì, dimmi due o tre demo di WoodMart che ti piacciono e le prendo come riferimento visivo — restano da rifare a mano, ma almeno miro a un bersaglio.

---

## 6. Deciso l'08/09

- Si parte da **A + B insieme** (catalogo + scheda prodotto), 7-9 giornate.
- Di WoodMart interessa **anche l'aspetto**: spaziature, ombre, gerarchia dei bottoni, colore dell'acquisto. In attesa da Laura di due o tre demo WoodMart come riferimento visivo.
- I nodi 1 e 2 (sconto sui KY o sugli euro, soglia spedizione) restano aperti: bloccano il blocco E, non A e B.

---

## 7. La barra dei filtri — misure vere (08/09)

Riferimento indicato da Laura: `woodmart.xtemos.com/electronics-3/product-category/smartphones/`. Misurato nel browser a 1440px.

**Cosa fa davvero quella demo.** La barra **non sta in pagina**: è un pannello a scomparsa (`wd-off-sidebar wd-left`) parcheggiato a `x = -340`, che si apre dal bottone dei filtri. La griglia usa tutti i 1395px e tiene quattro card da 341px. Il problema dello "spazio consumato a vuoto" lì è risolto **togliendo la barra dal flusso**, non stringendola.

**Quando è aperta:** pannello 340px, colonna di contenuto 265px, testo 16px/22,4 (Albert Sans). L'etichetta più lunga ("Wireless charging compatible") misura 219px: restano **46px vuoti, il 17%**. Su 145 voci, **2 vanno a capo** — nemmeno loro puntano allo zero.

**Le nostre etichette in Inter** (misurate sul font del portale, non stimate):

| Voce | 13px | 14px |
|---|---|---|
| Marketing e Comunicazione | 172px | 185px |
| Consulenza e Formazione | 160px | 172px |
| Elettronica e Tecnologia | 148px | 160px |
| Arte e intrattenimento | 133px | 144px |

Con casella 15px + spazio 8px + contatore ~26px + padding 16px per lato:

- **263px** → a 13px non va a capo nulla, contatore compreso;
- **237px** → a 13px nulla a capo, ma senza contatore delle occorrenze;
- **240px** → nulla a capo **se nel filtro le due voci lunghe si accorciano** in "Marketing" e "Consulenza" (sono etichette nostre, si cambiano);
- **276px** → la stessa cosa a 14px.

**Il conto va fatto sul portale, non su una pagina vuota.** Il menu del portale si prende gia' 272px (236 sotto i 1280) e `.content-shell` altri 28 di padding: su una finestra da 1440 al catalogo restano **1140px**, non 1440. Oggi `.catalog-grid` e' a **cinque colonne** fisse sopra i 1200 con gap 14 → card da 217px. Una seconda colonna di filtri da 264 + 20 di distacco lascia **856px**: cinque colonne diventerebbero card da 160, quattro colonne danno **204px**. La barra in pagina costa una colonna, e non c'e' larghezza che lo eviti.

**Il punto di equilibrio, deciso l'08/09.**

| | |
|---|---|
| Larghezza barra | **264px** |
| Testo dei filtri | **13px** |
| Categorie che vanno a capo | **nessuna** (contatori compresi) |
| Spazio vuoto dopo la voce piu' lunga | **18px** (WoodMart: 46) |
| Catalogo con la barra in pagina | **4 colonne**, card 204px |
| Barra in pagina | da **1440px** di finestra in su |
| Barra a scomparsa | **sotto i 1440**, dal bottone "Filtri" |
| Prodotti per pagina | **da 15 a 24** |

Perche' questi numeri e non altri:

- **264 e non 240.** A 240 non vanno a capo solo accorciando "Marketing e Comunicazione" e "Consulenza e Formazione" nel filtro. Ventiquattro pixel non valgono due etichette storpiate, e comunque non cambiano il numero di colonne: sia 240 sia 264 danno quattro.
- **264 e non 290.** Oltre i 280 lo spazio vuoto a destra supera i 30px e si vede: e' l'errore che WoodMart fa (46px, il 17% della colonna).
- **13px e non 14.** A 14px la stessa promessa costa 276px, e i filtri non sono testo da leggere: sono etichette da scorrere.
- **Sotto i 1440 a scomparsa.** A 1280 il catalogo scenderebbe a tre colonne con la barra in pagina: li' una colonna fissa costa davvero una card per riga, ed e' lo "spazio consumato a vuoto". A scomparsa la griglia resta piena e i filtri arrivano quando servono — la stessa scelta della demo indicata da Laura, che quella barra non la tiene affatto in pagina.
- **24 per pagina.** Il commento in `portal.blade.php:823` avverte che i 15 di `paginate()` devono restare divisibili per ogni conteggio di colonne. Con quattro colonne 15 lascia l'ultima riga spaiata; 24 si divide per 4, 3 e 2, e regge il "carica altri" del blocco A.

**Il resto della regola.** Contatori allineati a destra. Nomi dei venditori su una riga sola con i puntini e il titolo per esteso al passaggio del mouse: sono di lunghezza libera, nessuna larghezza li contiene tutti. La barra si scrive come token nel foglio dello shop (`--shop-filter-w: 264px`), non come numero sparso nelle viste.

Prova interattiva consegnata in chat l'08/09 (`barra-filtri-larghezza.html`): scena a misura vera, menu del portale compreso.

---

## 8. Due barre laterali: come si risolve

Il problema, in numeri: menu del portale 272 + filtri 264 + distacchi = **536px di cornice prima del primo prodotto**, il 39% di una finestra da 1440. Il catalogo scende da cinque colonne a quattro. Non e' un problema di larghezza dei filtri — e' che le due barre stanno larghe **nello stesso momento**.

**La soluzione: una alla volta.** Il menu del portale impara a stringersi a **68px di sole icone** (rail), con un clic sulla freccia in cima. Con menu a rail e filtri aperti la cornice torna al **24%** e il catalogo tiene **cinque colonne da 201px** — cioe' quanto oggi, che i filtri non ci sono nemmeno.

| A 1440 | Al catalogo | Colonne | Card | Cornice |
|---|---|---|---|---|
| menu disteso, filtri aperti | 856px | 4 | 204px | 39% |
| **menu a icone, filtri aperti** | **1060px** | **5** | **201px** | **24%** |
| menu a icone, filtri chiusi | 1344px | 6 | 212px | 5% |

### Come si scrive

1. **`--nav-w` al posto del numero.** Oggi la larghezza del menu e' cablata due volte in `portal.blade.php` (`grid-template-columns: 272px minmax(0,1fr)` alla riga 371 e nella `@media (min-width:769px)` con `!important`, piu' 236px sotto i 1280). Diventano `var(--nav-w)`, con `--nav-w: 272px` di base e `body.nav-rail { --nav-w: 68px }`.
2. **Niente lampeggio al caricamento.** La classe si applica **prima del primo paint** dallo stesso script in testa al `<head>` che gia' fa questo per il tema (riga 26, `km-theme`): una riga in piu' che legge `km-nav`. Se lo stato si applicasse a fine pagina, ogni caricamento mostrerebbe il menu largo che si stringe di scatto.
3. **Il menu a rail resta un menu.** Le icone ci sono gia' (`.nav-icon`): a rail spariscono le etichette e i gruppi a fisarmonica si chiudono, il nome torna nel `title` e in un fumetto al passaggio del mouse. Bottone con `aria-expanded`, navigabile da tastiera. **Sotto i 768px non cambia niente**: li' il menu e' gia' un pannello con l'hamburger, e il rail non si applica.
4. **I filtri si aprono e si chiudono.** `<aside>` largo `--shop-filter-w: 264px`, bottone "Filtri" nella barra degli strumenti, stato in `localStorage` (`km-shop-filters`). **Senza JavaScript l'aside resta aperto**: il negozio non deve mai perdere i filtri, come il mini-carrello che gia' oggi funziona a JS spento.
5. **La regola dell'una alla volta.** Sotto i 1500px di finestra, aprire i filtri **ritira il menu a rail da solo**. Se dopo l'utente lo ridistende a mano, la sua scelta vince e non viene piu' toccata per quella sessione: l'automatismo suggerisce, non comanda.
6. **Prodotti per pagina: 20.** Con le colonne che ora variano (6/5/4/2), 15 e 24 lasciano l'ultima riga spaiata quasi ovunque; 20 si divide per 5, 4 e 2. Il commento in `portal.blade.php:823` va aggiornato di conseguenza.

### Cosa ho scartato

- **Filtri orizzontali in cima** (come la barra di oggi): con attributi, fascia di prezzo, venditore e quota KY diventano un muro di controlli che spinge il primo prodotto sotto la piega.
- **Filtri in finestra sopra il catalogo**: mentre filtri non vedi l'effetto di quello che stai filtrando — ed e' proprio il momento in cui serve vederlo.
- **Restringere solo i filtri**: sotto i 240px le categorie vanno a capo, e comunque non basterebbe: il problema sono i 272 del menu, non i 264 dei filtri.

**Attenzione:** il rail tocca il layout di **tutto** il portale, non solo dello shop. E' una variabile sola, ma va vista su ogni pagina — dashboard, tabelle, moduli — prima di dirla finita.

Prova cliccabile consegnata in chat l'08/09: `due-barre-una-alla-volta.html`.

---

## 9. Fatto l'08/09 — le due barre (nessun commit)

Scritto, provato sul portale vero e lasciato nella working copy. **Nessun commit**, come sempre.

**File toccati:** `resources/views/layouts/portal.blade.php`, `resources/views/portal/shop.blade.php`, `public/assets/css/shop.css`, `app/Http/Controllers/ListingController.php`, `tests/Feature/UltimiAttritiBlocco5Test.php`. **Nuovo:** `tests/Feature/DueBarreLateraliTest.php` (13 test).

**Misurato sul portale in esecuzione** (non su un mock), finestra 1440, con 21 prodotti a catalogo:

| | menu | filtri | colonne | card |
|---|---|---|---|---|
| menu disteso, filtri aperti | 272 | 264 | 4 | 204px |
| **menu a icone, filtri aperti** | **68** | **264** | **5** | **201px** |
| menu a icone, filtri chiusi | 68 | — | 6 | 212px |

Le previsioni del capitolo 8 erano esatte al pixel. Provato anche: lo stato sopravvive alla ricarica senza lampeggi, a 1300px aprire i filtri ritira il menu da solo, su telefono (390px) il pannello entra da sinistra con lo sfondo scuro, si chiude con Esc e la pagina non scorre in orizzontale.

**Due cose trovate lavorando, che nessun ragionamento aveva previsto:**

1. **Il bottone del menu non si vedeva.** `.rail-btn { display: none; }` stava **dopo** la `@media` che lo accende: stessa specificita', vince l'ultima. Il bottone era nell'HTML, aveva l'aria giusta, rispondeva ai click da codice — e sullo schermo non c'era. Ora il default sta prima, e un test sorveglia l'ordine.
2. **Il menu a icone traboccava.** `.sidebar-inner` e' una griglia: la colonna e' larga quanto il contenuto piu' largo. Scesa a 68px, il contenuto restava a 140 e le icone uscivano mezze dal bordo. Risolto con `minmax(0, 1fr)` e `overflow-x: hidden`.

**Cinque test che erano rossi da prima, ora verdi.** Non erano regressioni di oggi (verificato su HEAD): tre del mini-carrello cadevano perche' un commento dentro `<style>` citava per esteso il testo del bottone d'acquisto, e i commenti finiscono nell'HTML servito; due della barra filtri cercavano dentro la pagina regole CSS che dal 04/09 vivono in `shop.css`. Commento riscritto, test aggiornati alla realta'.

**Suite: 1833 verdi, 0 rossi** (erano 1826 verdi + 5 rossi).

**Cosa manca del blocco A:** ordinamento (prezzo, novita', piu' venduti), filtri per attributo e fascia di prezzo, etichette Novita'/Saldo, barra scorte, seconda immagine al passaggio, "carica altri". La colonna dei filtri adesso e' il posto dove metterli — oggi contiene i tre filtri che c'erano gia'.
