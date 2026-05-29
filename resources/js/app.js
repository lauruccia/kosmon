import './bootstrap';

// Registra KMoney come W3C Payment Handler una sola volta al boot
import { registerKyPaymentHandler } from './ky-payment-request.js';
if (navigator.serviceWorker) {
    navigator.serviceWorker.ready.then(() => registerKyPaymentHandler()).catch(() => {});
}
