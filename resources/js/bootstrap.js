import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Laravel Echo — real-time WebSocket.
 * Inizializzato solo se le variabili VITE_REVERB_* o VITE_PUSHER_* sono presenti.
 * Se non configurato, le view QR/NFC cadono automaticamente sul polling AJAX.
 */
if (import.meta.env.VITE_REVERB_APP_KEY) {
    import('laravel-echo').then(({ default: Echo }) => {
        import('pusher-js').then(({ default: Pusher }) => {
            window.Pusher = Pusher;
            window.Echo = new Echo({
                broadcaster:     'reverb',
                key:             import.meta.env.VITE_REVERB_APP_KEY,
                wsHost:          import.meta.env.VITE_REVERB_HOST ?? 'localhost',
                wsPort:          import.meta.env.VITE_REVERB_PORT ?? 8080,
                wssPort:         import.meta.env.VITE_REVERB_PORT ?? 443,
                forceTLS:        (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
                enabledTransports: ['ws', 'wss'],
            });
            // Segnala alle view che Echo è disponibile
            window.dispatchEvent(new CustomEvent('echo-ready'));
        });
    });
} else if (import.meta.env.VITE_PUSHER_APP_KEY) {
    import('laravel-echo').then(({ default: Echo }) => {
        import('pusher-js').then(({ default: Pusher }) => {
            window.Pusher = Pusher;
            window.Echo = new Echo({
                broadcaster:     'pusher',
                key:             import.meta.env.VITE_PUSHER_APP_KEY,
                cluster:         import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'eu',
                forceTLS:        true,
            });
            window.dispatchEvent(new CustomEvent('echo-ready'));
        });
    });
}
