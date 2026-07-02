@extends('layouts.portal')

@section('content')
<section class="page-intro--row page-intro">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <a href="{{ $cancelRoute }}" style="font-size:12px;color:var(--text-muted);text-decoration:none;display:flex;align-items:center;gap:4px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
            Annulla e torna indietro
        </a>
    </div>
    <h2 style="margin-top:6px;color:#dc2626;">⚠️ Eliminazione definitiva</h2>
    <p>{{ $mode === 'company' ? "Stai per eliminare l'intera azienda \"{$targetLabel}\"." : "Stai per eliminare il conto privato di \"{$targetLabel}\"." }}</p>
</section>

<div style="max-width:640px;">
    <section class="card card-pad" style="border: 1.5px solid #fca5a5;margin-bottom:20px;">
        <div class="eyebrow" style="margin-bottom:12px;color:#dc2626;">Cosa verrà eliminato</div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
            <div>
                <div style="font-size:11px;color:var(--text-muted);font-weight:700;">CONTI</div>
                <div style="font-size:22px;font-weight:800;">{{ $preview['accounts'] }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text-muted);font-weight:700;">UTENTI</div>
                <div style="font-size:22px;font-weight:800;">{{ $preview['users'] }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text-muted);font-weight:700;">MOVIMENTI (incl. controparti)</div>
                <div style="font-size:22px;font-weight:800;">{{ $preview['transfers'] }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text-muted);font-weight:700;">SALDO ATTUALE</div>
                <div style="font-size:22px;font-weight:800;{{ $preview['balance_total'] < 0 ? 'color:#dc2626;' : '' }}">
                    {{ ky_format($preview['balance_total']) }} KY
                </div>
            </div>
        </div>

        <p style="font-size:13px;color:var(--text-muted);margin-bottom:0;">
            Ogni movimento eliminato ripristina il saldo di <strong>entrambi</strong> i conti coinvolti (anche le
            controparti reali, se presenti): il circuito resta bilanciato a 0. Verranno rimossi anche KYC,
            contratti, credenziali, card NFC, shop/annunci e ogni altro record collegato. L'unica traccia che
            resterà è una singola riga nell'audit log che documenta questa cancellazione.
        </p>
    </section>

    @if($preview['has_real_money'])
    <section class="card card-pad" style="border: 1.5px solid #dc2626;background:#fef2f2;margin-bottom:20px;">
        <div class="eyebrow" style="margin-bottom:8px;color:#991b1b;">🛑 Soldi reali rilevati</div>
        <p style="font-size:13px;color:#991b1b;margin-bottom:0;">
            Su questo conto risultano acquisti KY Card <strong>completati o rimborsati</strong> (pagamenti reali via
            Stripe/PayPal, avvenuti fuori dal circuito KY). Non sembrano dati di prova. L'operazione è bloccata di
            default: puoi forzarla solo se sei certo che vada comunque eliminato.
        </p>
    </section>
    @endif

    <section class="card card-pad">
        <form method="POST" action="{{ $submitRoute }}" id="purge-form">
            @csrf

            @if($preview['has_real_money'])
            <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;margin-bottom:16px;color:#991b1b;">
                <input type="checkbox" name="force" value="1" style="margin-top:3px;">
                Forza comunque la cancellazione nonostante i pagamenti reali rilevati.
            </label>
            @endif

            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">
                Per confermare, digita esattamente:
                <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;">{{ $targetLabel }}</code>
            </label>
            <input type="text" name="confirmation" id="confirmation-input" autocomplete="off"
                class="form-input" style="width:100%;margin-bottom:16px;" placeholder="{{ $targetLabel }}">

            <div style="display:flex;gap:10px;">
                <button type="submit" id="purge-submit" class="cta" style="background:#dc2626;opacity:0.5;pointer-events:none;" disabled>
                    Elimina definitivamente
                </button>
                <a href="{{ $cancelRoute }}" class="cta" style="background:var(--border);color:var(--text);">
                    Annulla
                </a>
            </div>
        </form>
    </section>
</div>

<script>
    (function () {
        var expected = @json($targetLabel);
        var input = document.getElementById('confirmation-input');
        var submit = document.getElementById('purge-submit');
        var form = document.getElementById('purge-form');

        function refresh() {
            var match = input.value.trim() === expected.trim();
            submit.disabled = !match;
            submit.style.opacity = match ? '1' : '0.5';
            submit.style.pointerEvents = match ? 'auto' : 'none';
        }

        input.addEventListener('input', refresh);

        form.addEventListener('submit', function (e) {
            if (input.value.trim() !== expected.trim()) {
                e.preventDefault();
                return;
            }
            if (!confirm('Confermi la cancellazione DEFINITIVA e IRREVERSIBILE? Non è possibile tornare indietro.')) {
                e.preventDefault();
            }
        });
    })();
</script>
@endsection
