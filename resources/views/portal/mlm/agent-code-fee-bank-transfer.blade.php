@extends('layouts.portal')

@section('content')
<div style="max-width:560px;">

    <div style="margin-bottom:24px;">
        <a href="{{ route('portal.mlm.agent-code-fee.show') }}" style="font-size:13px;color:var(--teal-strong);text-decoration:none;">&larr; Torna ai metodi di pagamento</a>
    </div>

    <div class="card" style="padding:28px;">

        @include('portal.mlm._passi', ['passo' => 1])

        <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;">
            <div style="width:48px;height:48px;border-radius:12px;background:#fef3c7;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;">&#127968;</div>
            <div>
                <div style="font-size:18px;font-weight:800;color:var(--ink);">Istruzioni per il bonifico</div>
                <div style="font-size:13px;color:var(--ink-soft);margin-top:2px;">Potrai firmare il contratto di nomina quando il bonifico sar&agrave; verificato, entro 1-2 giorni lavorativi.</div>
            </div>
        </div>

        @php
            $fields = [
                'Beneficiario' => $bankBeneficiary,
                'Banca'        => $bankName,
                'IBAN'         => $bankIban,
                'Importo'      => number_format($payment->amount_eur, 2, ',', '.') . ' EUR',
                'Causale'      => $payment->bank_transfer_reference,
            ];
        @endphp

        @foreach($fields as $label => $value)
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9;gap:12px;">
            <span style="font-size:13px;color:var(--ink-muted);min-width:110px;">{{ $label }}</span>
            <span style="font-size:14px;font-weight:{{ in_array($label, ['Causale','IBAN']) ? '700' : '600' }};color:{{ $label === 'Causale' ? '#7c3aed' : 'var(--ink)' }};font-family:{{ in_array($label, ['IBAN','Causale']) ? 'monospace' : 'inherit' }};word-break:break-all;text-align:right;">
                {{ $value ?: '—' }}
            </span>
        </div>
        @endforeach

        <div class="notice" style="margin-top:20px;">
            Indica la causale <strong>{{ $payment->bank_transfer_reference }}</strong> esattamente com'&egrave; scritta:
            &egrave; l'unico modo che abbiamo per collegare il bonifico alla tua posizione.
        </div>

    </div>
</div>
@endsection
