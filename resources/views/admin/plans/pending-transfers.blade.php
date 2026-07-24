@extends('layouts.portal')

@section('page-actions')
<a href="{{ route('admin.plans.index') }}" class="cta secondary">Gestione piani</a>
@endsection

@section('content')
<div style="width:100%;">

    @if(session('success'))
        <div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:10px;">
            {{ session('success') }}
        </div>
    @endif

    @if($payments->isEmpty())
        <div class="card light-card" style="padding:32px;text-align:center;color:var(--ink-muted);">
            <div style="font-size:28px;margin-bottom:6px;">&#10003;</div>
            <strong>Nessun bonifico upgrade piano in attesa.</strong>
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:8px;">
        @foreach($payments as $payment)
        <div class="card light-card" style="padding:10px 14px;">
            <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">

                <div style="flex:1;min-width:220px;">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <span style="font-size:13px;font-weight:700;color:var(--ink);">{{ $payment->company->name ?? '—' }}</span>
                        <span style="font-size:11px;background:#fffbeb;color:#92400e;padding:1px 7px;border-radius:20px;font-weight:600;">&#9203; Attende conferma</span>
                        <span style="font-size:11px;background:#f0fdf4;color:#166534;padding:1px 7px;border-radius:20px;font-weight:600;">{{ number_format($payment->amount_cents / 100, 2, ',', '.') }} &euro;</span>
                        <span style="font-size:11px;background:#eff6ff;color:#1d4ed8;padding:1px 7px;border-radius:20px;font-weight:600;">{{ $payment->fromPlan->name ?? '—' }} → {{ $payment->toPlan->name ?? '—' }}</span>
                    </div>
                    <div style="margin-top:3px;font-size:11px;color:var(--ink-soft);">
                        <strong>Richiesto da:</strong> {{ $payment->user->name ?? '—' }} &nbsp;|&nbsp;
                        <strong>Ordine:</strong> {{ $payment->created_at->format('d/m/Y H:i') }} &nbsp;|&nbsp;
                        <strong>Causale:</strong> <span style="font-family:monospace;font-weight:700;color:#7c3aed;">{{ $payment->bank_transfer_reference }}</span>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:8px;">
                    <textarea id="notes-{{ $payment->id }}" rows="1"
                              style="width:190px;padding:5px 8px;border:1px solid var(--border);border-radius:6px;font-size:11px;resize:none;"
                              placeholder="Note admin (opzionale)"></textarea>

                    @php
                        $confirmMsg = 'Confermi il bonifico e attivi il piano ' . addslashes($payment->toPlan->name ?? '') . ' per ' . addslashes($payment->company->name ?? '') . '?';
                    @endphp

                    <form method="POST" action="{{ route('admin.plans.confirm-transfer', $payment) }}"
                          onsubmit="this.querySelector('[name=admin_notes]').value = document.getElementById('notes-{{ $payment->id }}').value;">
                        @csrf
                        <input type="hidden" name="admin_notes" value="">
                        <button type="submit" class="cta" style="font-size:11px;padding:4px 12px;"
                                onclick="return confirm('{{ $confirmMsg }}')">&#10003; Conferma</button>
                    </form>

                    <form method="POST" action="{{ route('admin.plans.reject-transfer', $payment) }}"
                          onsubmit="this.querySelector('[name=admin_notes]').value = document.getElementById('notes-{{ $payment->id }}').value;">
                        @csrf
                        <input type="hidden" name="admin_notes" value="">
                        <button type="submit" class="cta secondary" style="font-size:11px;padding:4px 12px;background:#fef2f2;color:#991b1b;border-color:#fecaca;"
                                onclick="return confirm('Rifiutare questo bonifico?')">&#10007; Rifiuta</button>
                    </form>
                </div>

            </div>
        </div>
        @endforeach
        </div>
        <div style="margin-top:16px;">{{ $payments->links() }}</div>
    @endif

</div>
@endsection
