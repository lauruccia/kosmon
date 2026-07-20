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

    'mlm_enabled' => env('MLM_ENABLED', true),

];
