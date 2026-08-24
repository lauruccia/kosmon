<?php

return [

    /*
    |--------------------------------------------------------------------------
    | "Accedi con KMoney" — server OAuth2 minimale
    |--------------------------------------------------------------------------
    |
    | KMoney fa da identity provider per le applicazioni del circuito (oggi
    | soltanto Kosmoshop). Il flusso implementato è UNO solo: authorization_code
    | con PKCE obbligatorio. Non esistono altri grant.
    |
    | I client NON stanno a database: sono pochi, di prima parte, e vivono qui.
    | Aggiungerne uno significa aggiungere una voce a questo array e tre righe
    | nel .env — revocarlo significa cancellare il segreto dal .env, senza
    | toccare il database.
    |
    */

    'clients' => [

        'kshop' => [
            'name'          => env('OAUTH_KSHOP_NAME', 'Kosmoshop'),
            'client_id'     => env('OAUTH_KSHOP_CLIENT_ID'),
            'secret'        => env('OAUTH_KSHOP_CLIENT_SECRET'),

            // Elenco chiuso di URI di ritorno, separate da virgola. Il confronto
            // è per stringa intera: nessun prefisso, nessun jolly (è da lì che
            // nascono gli open redirect).
            'redirect_uris' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('OAUTH_KSHOP_REDIRECT_URIS', ''))
            ))),

            // Scope che questo client può chiedere. Chiederne altri = errore.
            'scopes'        => ['profile', 'account.read', 'orders.write', 'mandate'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scope disponibili
    |--------------------------------------------------------------------------
    |
    | La chiave è lo scope, il valore è la frase mostrata all'utente nella
    | pagina di consenso. Se uno scope non è qui dentro, non esiste.
    |
    */

    'scopes' => [
        'profile'      => 'Sapere chi sei: nome, cognome ed email',
        'account.read' => 'Vedere il tuo numero di conto KY e se puoi comprare o vendere',
        'orders.write' => 'Creare ordini a tuo nome',
        'mandate'      => 'Chiederti di autorizzare i pagamenti in un clic',
    ],

    /*
    |--------------------------------------------------------------------------
    | Durate (in secondi)
    |--------------------------------------------------------------------------
    |
    | Il codice di autorizzazione dura pochissimo di proposito: è un biglietto
    | usa e getta che viaggia nella barra degli indirizzi.
    |
    */

    'ttl' => [
        'authorization_code' => 60,               // 1 minuto
        'access_token'       => 3600,             // 1 ora
        'refresh_token'      => 60 * 60 * 24 * 30, // 30 giorni
    ],

];
