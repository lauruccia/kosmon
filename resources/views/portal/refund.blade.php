@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('portal.movements') }}">← Torna ai movimenti</a>
@endsection




@section('content')
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<div class="summary-grid" style="margin-bottom:24px;">

    {{-- Form rimborso --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Nuovo rimborso</span>
                <h3 class="section-title">Rimborsa il movimento</h3>
            </div>
            <span style="font-size:22px;">↩️</span>
        </div>

        @if($errors->any())
            <div style="background:#ffe4e6;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                <strong style="color:#9f1239;font-size:13px;">Correggi i seguenti errori:</strong>
                <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;color:#9f1239;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Riepilogo movimento originale --}}
        <div style="background:var(--bg);border:1.5px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:20px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:10px;">Movimento originale</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <div style="font-size:12px;color:var(--ink-muted);">Da</div>
                    <div style="font-weight:600;font-size:14px;">{{ $transfer->fromAccount?->display_name ?? '—' }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);">{{ $transfer->fromAccount?->account_number }}</div>
                </div>
                <div>
                    <div style="font-size:12px;color:var(--ink-muted);">A (tu)</div>
                    <div style="font-weight:600;font-size:14px;">{{ $transfer->toAccount?->display_name ?? '—' }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);">{{ $transfer->toAccount?->account_number }}</div>
                </div>
                <div>
                    <div style="font-size:12px;color:var(--ink-muted);">Importo totale</div>
                    <div style="font-weight:800;font-size:20px;color:#0f52c4;">{{ ky_format($transfer->amount) }} KY</div>
                </div>
                <div>
                    <div style="font-size:12px;color:var(--ink-muted);">Già rimborsato</div>
                    <div style="font-weight:700;font-size:18px;color:{{ $alreadyRefunded > 0 ? '#f59e0b' : 'var(--ink-muted)' }};">
                        {{ ky_format($alreadyRefunded) }} KY
                    </div>
                </div>
            </div>
            @if($transfer->description)
            <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line);font-size:13px;color:var(--ink-soft);">
                <span style="font-weight:600;">Causale:</span> {{ $transfer->description }}
            </div>
            @endif
            <div style="margin-top:8px;font-size:11px;color:var(--ink-muted);">
                Rif. <code style="background:var(--bg-alt,#f5f8fb);padding:1px 5px;border-radius:4px;">{{ $transfer->reference }}</code>
                · {{ $transfer->booked_at?->format('d/m/Y H:i') }}
            </div>
        </div>

        {{-- Importo disponibile --}}
        <div style="background:#d1fae5;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#065f46;border:1px solid #a7f3d0;">
            <strong>Massimo rimborsabile:</strong>
            <span style="font-size:16px;font-weight:800;margin-left:6px;">{{ ky_format($maxRefundable) }} KY</span>
        </div>

        <form method="POST" action="{{ route('portal.refund.submit', $transfer) }}" id="refundForm">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">

            {{-- Importo --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="amount">
                    Importo da rimborsare (KY) <span style="color:#dc2626;">*</span>
                </label>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                    <button type="button" class="cta secondary" style="font-size:12px;padding:6px 12px;"
                        onclick="document.getElementById('amount').value = {{ number_format($maxRefundable / 100, 2, '.', '') }}; updatePreview();">
                        Rimborso totale
                    </button>
                    <span style="font-size:12px;color:var(--ink-muted);">oppure inserisci importo parziale</span>
                </div>
                <div style="position:relative;">
                    <input type="number" name="amount" id="amount" required
                        min="0.01" max="{{ number_format($maxRefundable / 100, 2, '.', '') }}" step="0.01"
                        value="{{ old('amount') }}"
                        placeholder="es. {{ number_format($maxRefundable / 100, 2, '.', '') }}"
                        style="width:100%;padding:11px 60px 11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:18px;font-weight:700;">
                    <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-weight:700;color:var(--ink-muted);font-size:14px;">KY</span>
                </div>
            </div>

            {{-- Causale rimborso --}}
            <div style="margin-bottom:22px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="description">
                    Motivazione del rimborso
                </label>
                <textarea name="description" id="description" rows="2"
                    placeholder="Es: Reso merce, servizio non erogato, errore di fatturazione"
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;resize:vertical;">{{ old('description') }}</textarea>
            </div>

            {{-- Preview --}}
            <div id="preview" style="display:none;background:#fef3c7;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:14px;color:#92400e;border:1px solid #fde68a;">
                <strong>Riepilogo:</strong> Stai rimborsando
                <strong id="previewAmount">—</strong> KY
                a <strong>{{ $transfer->fromAccount?->display_name ?? '—' }}</strong>.
                Il tuo saldo si ridurrà di quell'importo.
            </div>

            <button type="submit" class="cta" style="width:100%;font-size:16px;padding:14px;background:#f59e0b;border-color:#f59e0b;"
                onclick="return confirm('Confermi il rimborso? L\'operazione sarà contabilizzata immediatamente.')">
                ↩️ Conferma rimborso
            </button>
        </form>
    </section>

    {{-- Info box --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Informazioni</span>
                <h3 class="section-title">Come funziona il rimborso</h3>
            </div>
        </div>

        <div style="display:grid;gap:16px;">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:20px;flex-shrink:0;">📒</span>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Double-entry ledger</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">
                        Il rimborso genera due voci nel ledger: un debito sul tuo conto e un credito
                        sul conto del pagante originale. La somma rimane zero.
                    </span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:20px;flex-shrink:0;">⚡</span>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Immediato</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">
                        A differenza di una richiesta di pagamento, il rimborso viene contabilizzato
                        subito — non richiede conferma dall'altra parte.
                    </span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:20px;flex-shrink:0;">🔢</span>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Rimborso parziale</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">
                        Puoi fare più rimborsi parziali sullo stesso movimento, fino a esaurire
                        l'importo originale ({{ ky_format($transfer->amount) }} KY).
                    </span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:20px;flex-shrink:0;">🔔</span>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Notifica automatica</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">
                        Il pagante originale riceverà una notifica email e in-app con i dettagli
                        del rimborso.
                    </span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:20px;flex-shrink:0;">⚠️</span>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Irreversibile</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">
                        Il rimborso non può essere annullato. Per correzioni contatta l'amministratore
                        del circuito.
                    </span>
                </div>
            </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--line);margin:20px 0;">

        <div style="text-align:center;padding:16px;background:var(--bg);border-radius:12px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:4px;">Rimborso a</div>
            <div style="font-size:18px;font-weight:800;">{{ $transfer->fromAccount?->display_name ?? '—' }}</div>
            <div style="font-size:13px;color:var(--ink-muted);">{{ $transfer->fromAccount?->account_number }}</div>
        </div>
    </section>

</div>

<script>
const amountInput   = document.getElementById('amount');
const preview       = document.getElementById('preview');
const previewAmount = document.getElementById('previewAmount');

function updatePreview() {
    const val = parseFloat(amountInput.value);
    if (val > 0) {
        preview.style.display = 'block';
        previewAmount.textContent = val.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    } else {
        preview.style.display = 'none';
    }
}

amountInput.addEventListener('input', updatePreview);
</script>

@endsection
