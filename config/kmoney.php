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

];
