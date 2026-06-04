{{-- Mostra/nascondi password: aggiunge un "occhietto" a ogni input[type=password]. Auto-applicato, nessuna modifica ai singoli form richiesta. --}}
<style>
    .pw-toggle-wrap { position: relative; display: block; }
    .pw-toggle-wrap > input { padding-right: 44px !important; }
    .pw-toggle-btn {
        position: absolute;
        top: 0;
        right: 0;
        height: 100%;
        width: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        margin: 0;
        border: 0;
        background: transparent;
        cursor: pointer;
        color: #6b7c88;
        line-height: 0;
        -webkit-tap-highlight-color: transparent;
    }
    .pw-toggle-btn:hover { color: #1d3344; }
    .pw-toggle-btn:focus-visible { outline: 2px solid #4d7386; outline-offset: -2px; border-radius: 8px; }
    .pw-toggle-btn svg { width: 22px; height: 22px; display: block; pointer-events: none; }
    .pw-toggle-btn .pw-eye-off { display: none; }
    .pw-toggle-btn.is-visible .pw-eye { display: none; }
    .pw-toggle-btn.is-visible .pw-eye-off { display: block; }
</style>
<script>
(function () {
    var EYE = '<svg class="pw-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
            + '<svg class="pw-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function enhance(input) {
        if (!input || input.dataset.pwToggle === '1') return;
        if (input.type !== 'password') return;
        input.dataset.pwToggle = '1';

        var wrap = document.createElement('span');
        wrap.className = 'pw-toggle-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle-btn';
        btn.setAttribute('aria-label', 'Mostra password');
        btn.setAttribute('tabindex', '-1');
        btn.innerHTML = EYE;
        wrap.appendChild(btn);

        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-visible', show);
            btn.setAttribute('aria-label', show ? 'Nascondi password' : 'Mostra password');
        });
    }

    function run() {
        document.querySelectorAll('input[type="password"]').forEach(enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
</script>
