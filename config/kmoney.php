<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dati bancari per il bonifico (ricarica KY Card)
    |--------------------------------------------------------------------------
    |
    | Mostrati nella pagina "Istruzioni bonifico" quando l'utente sceglie il
    | pagamento via bonifico. I valori vengono letti dall'ambiente qui, nel
    | file di config: così restano corretti anche con la config in cache
    | (`php artisan config:cache`), dove env() a runtime tornerebbe null.
    |
    */

    'bank_iban'        => env('BANK_IBAN', 'IT00 X000 0000 0000 0000 0000 000'),
    'bank_name'        => env('BANK_NAME', 'Banca di riferimento'),
    'bank_beneficiary' => env('BANK_BENEFICIARY', 'KMoney S.r.l.'),

    /*
    |--------------------------------------------------------------------------
    | Feature flag: programma agenti MLM (KNM)
    |--------------------------------------------------------------------------
    |
    | Permette di deployare lo STESSO codice su installazioni diverse tenendo
    | il programma agenti MLM attivo su alcune (es. kosmopay.it) e spento su
    | altre (es. kmoney.it), senza branch/repo separati. A flag spento:
    |   - le rotte /mlm/* (portale) e /admin/mlm* restituiscono 404
    |     (middleware EnsureMlmEnabled, alias "mlm.enabled")
    |   - la registrazione utente NON risolve l'agente/non assegna punti
    |     MLM e NON marca gli inviti come registrati lato MLM
    |   - l'accredito punti su ricarica KY Card è saltato
    |   - i comandi schedulati mlm:recalculate-points, mlm:calculate-commissions,
    |     mlm:calculate-weekly-bonuses non vengono eseguiti
    |   - le voci di menu/CTA relative a MLM sono nascoste (sidebar admin e
    |     portale, checkbox "Voglio diventare agente KNM" in registrazione)
    |
    | Le migration MLM restano IDENTICHE ovunque (lo schema DB non cambia in
    | base al flag, solo il comportamento a runtime). Default: attivo, per
    | non alterare il comportamento delle installazioni esistenti che non
    | impostano esplicitamente MLM_ENABLED.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Shop: le due soglie che tengono in piedi il mix KY/EUR
    |--------------------------------------------------------------------------
    |
    | Nascono dall'audit del 26/08/2026 (AUDIT_ECOMMERCE_2026-08-26.md, blocco
    | 1, punti 1.4 e 1.5). Sono due numeri, ma servono a non lasciare mai
    | l'acquirente con i KY usciti e l'ordine bloccato.
    |
    | min_euro_quota - la quota in euro piu' piccola che un gateway accetta,
    | in CENTESIMI DI EURO. Stripe rifiuta gli incassi sotto i 50 centesimi:
    | un ordine da 25 centesimi di quota euro passerebbe l'addebito KY e poi
    | verrebbe respinto al pagamento, restando "in attesa" per sempre. Si
    | blocca in cassa PRIMA di muovere qualsiasi cosa.
    |
    | min_price_ky - il prezzo minimo di un prodotto, in CENTESIMI DI KY.
    | Sotto questa soglia la quota KY arrotondata puo' diventare zero (un
    | centesimo al 25% fa round(0,25) = 0) e il movimento non e' nemmeno
    | registrabile: in un carrello con piu' venditori una riga cosi' fa
    | fallire l'intero acquisto. 100 = 1,00 KY.
    |
    */

    'shop' => [
        'min_euro_quota' => (int) env('SHOP_MIN_EURO_QUOTA', 50),
        'min_price_ky'   => (int) env('SHOP_MIN_PRICE_KY', 100),
    ],

    'mlm_enabled' => env('MLM_ENABLED', true),

];
