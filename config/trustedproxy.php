<?php

/*
|--------------------------------------------------------------------------
| Trusted Proxies
|--------------------------------------------------------------------------
|
| Letto NATIVAMENTE da Illuminate\Http\Middleware\TrustProxies a request
| time quando trustProxies(at:) non e' impostato in bootstrap/app.php.
| Essendo un file di config, il valore viene "cotto" da `config:cache` e
| funziona anche quando .env non viene caricato — a differenza di env()
| chiamato a runtime.
|
| Valori: CSV di IP/range (es. i range Cloudflare) oppure '*' (opt-in
| esplicito: X-Forwarded-For diventa falsificabile dal client).
| Default sicuro: loopback + reti private (vedi trusted_proxies() in
| app/helpers.php).
|
*/

return [

    'proxies' => trusted_proxies(),

];
