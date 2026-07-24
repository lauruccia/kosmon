@extends('layouts.portal')

@section('content')
<div style="max-width:560px;">

    <div style="margin-bottom:24px;">
        <a href="{{ route('portal.plan.index') }}" style="font-size:13px;color:var(--teal-strong);text-decoration:none;">&larr; Il mio piano</a>
    </div>

    <div class="card" style="padding:28px;">

        <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;">
            <div style="width:48px;height:48px;border-radius:12px;background:#fef3c7;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;">
                &#127968;
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:var(--ink);">Istruzioni per il bonifico</div>
                <div style="font-size:13px;color:var(--ink-soft);margin-top:2px;">Il piano "{{ $targetPlan->name }}" si attiva dopo verifica del bonifico da parte dell'amministrazione.</div>
            </div>
        </div>

        <div style="background:#f8fafc;border-radius:10px;padding:16px;margin-bottom:20px;">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-muted);margin-bottom:10px;">Riepilogo</div>
            <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                <span style="font-size:14px;color:var(--ink-soft);">Nuovo piano</span>
                <span style="font-size:14px;font-weight:700;">{{ $targetPlan->name }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding-top:10px;border-top:1px solid var(--border);">
                <span style="font-size:15px;font-weight:700;">Importo da pagare</span>
                <span style="font-size:18px;font-weight:800;color:var(--ink);">{{ number_format($payment->amount_cents / 100, 2, ',', '.') }} &euro;</span>
            </div>
        </div>

        <div style="margin-bottom:20px;">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-muted);margin-bottom:10px;">Dati per il bonifico</div>

            @php
                $fields = [
                    'Beneficiario'  => $bankBeneficiary,
                    'Banca'         => $bankName,
                    'IBAN'          => $bankIban,
                    'Importo'       => number_format($payment->amount_cents / 100, 2, ',', '.') . ' EUR',
                    'Causale'       => $payment->bank_transfer_reference,
                ];
            @endphp

            @foreach($fields as $label => $value)
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:13px;color:var(--ink-muted);min-width:110px;">{{ $label }}</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:{{ $label === 'IBAN' ? '13px' : '14px' }};font-weight:{{ in_array($label, ['Causale','IBAN']) ? '700' : '600' }};color:{{ $label === 'Causale' ? '#7c3aed' : 'var(--ink)' }};font-family:{{ in_array($label, ['IBAN','Causale']) ? 'monospace' : 'inherit' }};">
                        {{ $value }}
                    </span>
                    <button type="button"
                        onclick="copyText('{{ $value }}', this)"
                        style="background:#f1f5f9;border:none;border-radius:6px;padding:3px 8px;font-size:11px;cursor:pointer;color:var(--ink-muted);">
                        Copia
                    </button>
                </div>
            </div>
            @endforeach
        </div>

        <div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:10px;padding:14px;margin-bottom:20px;">
            <div style="font-size:13px;font-weight:700;color:#6d28d9;margin-bottom:4px;">&#9888; Usa esattamente questa causale</div>
            <div style="color:#7c3aed;font-family:monospace;font-size:15px;font-weight:700;letter-spacing:.05em;">
                {{ $payment->bank_transfer_reference }}
            </div>
            <div style="font-size:12px;color:#6d28d9;margin-top:6px;">
                La causale permette di identificare il tuo pagamento e attivare il piano manualmente da parte dell'amministrazione.
            </div>
        </div>

        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:24px;font-size:13px;color:#92400e;">
            &#9203; Il tuo upgrade è in attesa. Riceverai una notifica email non appena il bonifico viene confermato.
        </div>

        <div style="display:flex;gap:12px;">
            <a href="{{ route('portal.plan.index') }}" class="cta secondary" style="flex:1;justify-content:center;">Torna al mio piano</a>
            <a href="{{ route('portal.dashboard') }}" class="cta" style="flex:1;justify-content:center;">Vai al conto</a>
        </div>

    </div>
</div>

<script>
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(function() {
        var orig = btn.textContent;
        btn.textContent = 'Copiato!';
        btn.style.background = '#dcfce7';
        btn.style.color = '#166534';
        setTimeout(function() {
            btn.textContent = orig;
            btn.style.background = '#f1f5f9';
            btn.style.color = '';
        }, 1800);
    });
}
</script>
@endsection
