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

            // Il canale con cui KMoney avvisa l'applicazione degli eventi che
            // non nascono da una sua richiesta: un ordine confermato a mano
            // dall'utente, un'azienda che entra o esce dal debito. Stesso
            // interruttore del resto: URL vuoto = canale spento. E senza
            // segreto non si spedisce comunque, perché un webhook non firmato
            // non è verificabile da chi lo riceve.
            'webhook'       => [
                'url'    => env('OAUTH_KSHOP_WEBHOOK_URL'),
                'secret' => env('OAUTH_KSHOP_WEBHOOK_SECRET'),
            ],
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

    /*
    |--------------------------------------------------------------------------
    | Mandato di pagamento ("un clic e paghi")
    |--------------------------------------------------------------------------
    |
    | Il mandato NON è un abbonamento e non autorizza nessun addebito
    | ricorrente: dice una cosa sola, "da questo negozio non può uscire più di
    | N KY in un colpo solo". Sopra quella soglia non si rifiuta l'acquisto —
    | si chiede all'utente di confermarlo a mano.
    |
    */

    'mandate' => [

        // Tetto proposto nella schermata di consenso, in centesimi di KY.
        // 5000 = 50,00 KY (decisione di Laura, 25/08/2026). L'utente può
        // alzarlo o abbassarlo prima di confermare.
        'default_max_per_transaction' => 5000,

        // Estremi accettati per il tetto, sempre in centesimi.
        'min_max_per_transaction' => 100,      //     1,00 KY
        'max_max_per_transaction' => 100000,   // 1.000,00 KY

        // Scadenza di sicurezza del mandato.
        'expires_months' => 12,

        // Quanto vive il link con cui l'utente conferma a mano un acquisto che
        // il mandato non poteva addebitare da solo (fase 2b). È un checkout: o
        // si conferma subito o si riparte dal carrello. Scaduto il link, kshop
        // ne chiede semplicemente un altro. Decisione di Laura, 25/08/2026.
        'confirmation_ttl_minutes' => 30,

        // Antifurto: non è un limite di spesa che l'utente deve capire, è una
        // soglia tecnica. Dieci addebiti automatici in un'ora da un solo
        // negozio non è un comportamento umano normale: il mandato si sospende
        // da solo e l'utente riceve una notifica.
        'rate_limit' => [
            'max_charges'    => 10,
            'window_minutes' => 60,
        ],
    ],

];
