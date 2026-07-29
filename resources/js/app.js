import './bootstrap';

// Registra KMoney come W3C Payment Handler una sola volta al boot
import { registerKyPaymentHandler } from './ky-payment-request.js';
if (navigator.serviceWorker) {
    navigator.serviceWorker.ready.then(() => registerKyPaymentHandler()).catch(() => {});
}

// ── Toggle visibilità password ────────────────────────────────────────────────
const _eyeShow = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
const _eyeHide = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;

function _applyPasswordToggle(input) {
    if (input.dataset.pwToggle) return; // già applicato
    input.dataset.pwToggle = '1';

    // Avvolgi in .pw-wrap se non è già dentro uno
    if (!input.closest('.pw-wrap')) {
        const wrap = document.createElement('div');
        wrap.className = 'pw-wrap';
        wrap.style.position = 'relative';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
    }

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-eye';
    btn.setAttribute('aria-label', 'Mostra/nascondi password');
    btn.innerHTML = _eyeShow;
    btn.addEventListener('click', () => {
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        btn.innerHTML = visible ? _eyeShow : _eyeHide;
    });
    input.closest('.pw-wrap').appendChild(btn);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[type=password]').forEach(_applyPasswordToggle);
});

// ── Contatti nascosti (email/telefono) — rivelati al passaggio del mouse
// (:hover via CSS) o al click (per touch/mobile, dove :hover non esiste).
// Applica a qualunque elemento con classe "reveal-contact" in tutta l'app
// (directory aziende, profilo azienda, ecc.): un solo handler delegato,
// niente da ripetere nelle singole viste. Il primo click rivela soltanto
// (non segue subito un eventuale link mailto:/tel:), il secondo click
// prosegue normalmente.
document.addEventListener('click', (e) => {
    const el = e.target.closest('.reveal-contact');
    if (!el) return;
    if (!el.classList.contains('is-revealed')) {
        e.preventDefault();
        el.classList.add('is-revealed');
    }
});

// ── Tooltip contatti sulla card azienda (directory, punto 4 aggiornato
// 2026-07-29): email/telefono NON compaiono piu' in chiaro sulla card, sono
// visibili solo in un pannello che appare al passaggio del mouse sulla card
// (gestito in CSS, :hover) o al click (qui, per touch/mobile dove :hover non
// esiste). Il click viene ignorato se cade su un link/pulsante reale della
// card (Paga, Shop, Profilo, ecc.), cosi' la navigazione normale non viene
// mai intercettata — si attiva solo cliccando l'area informativa della card.
document.addEventListener('click', (e) => {
    if (e.target.closest('a, button')) return;
    const card = e.target.closest('.dir-card-has-tooltip');
    if (!card) return;
    card.classList.toggle('tooltip-open');
});
