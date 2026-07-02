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

// ── Filtro periodo: date "Da"/"A" modificabili solo su intervallo personalizzato ──
function _applyPeriodDateLock(select) {
    const form = select.closest('form');
    if (!form) return;
    const fromInput = form.querySelector('input[name="from_date"]');
    const toInput = form.querySelector('input[name="to_date"]');
    if (!fromInput || !toInput) return;

    const sync = () => {
        const isCustom = select.value === 'custom';
        [fromInput, toInput].forEach((input) => {
            input.disabled = !isCustom;
            input.classList.toggle('is-locked', !isCustom);
        });
    };

    select.addEventListener('change', sync);
    sync();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select[name="period"]').forEach(_applyPeriodDateLock);
});
