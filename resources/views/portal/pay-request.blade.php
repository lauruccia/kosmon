@extends('layouts.portal')

@section('content')

@if(session('portal_error'))
    <div class="alert alert-error" style="margin-bottom:24px;">{{ session('portal_error') }}</div>
@endif

<div class="portal-grid" style="--grid-cols:2;">

    {{-- Riquadro principale --}}
    <div class="stack">
        @if($pr->isPaid())
            {{-- Già pagata --}}
            <section class="card card-pad" style="text-align:center;">
                <div style="font-size:56px;margin-bottom:12px;">✅</div>
                <h3 style="margin-bottom:8px;">Già pagato</h3>
                <p style="color:var(--text-muted);margin-bottom:24px;">Questa richiesta di pagamento è già stata saldata.</p>
                <a href="{{ route('portal.dashboard') }}" class="cta">Vai al conto</a>
            </section>

        @elseif($pr->isExpired())
            {{-- Scaduta --}}
            <section class="card card-pad" style="text-align:center;">
                <div style="font-size:56px;margin-bottom:12px;">⏰</div>
                <h3 style="margin-bottom:8px;">QR scaduto</h3>
                <p style="color:var(--text-muted);margin-bottom:24px;">Questo QR non è più valido. Chiedi al commerciante di generarne uno nuovo.</p>
                <a href="{{ route('portal.dashboard') }}" class="cta secondary">Vai al conto</a>
            </section>

        @elseif($pr->status === 'cancelled')
            {{-- Annullata --}}
            <section class="card card-pad" style="text-align:center;">
                <div style="font-size:56px;margin-bottom:12px;">🚫</div>
                <h3 style="margin-bottom:8px;">Richiesta annullata</h3>
                <p style="color:var(--text-muted);margin-bottom:24px;">Il commerciante ha annullato questa richiesta di pagamento.</p>
                <a href="{{ route('portal.dashboard') }}" class="cta secondary">Vai al conto</a>
            </section>

        @else
            {{-- Pagabile --}}
            <section class="card card-pad">
                <div class="k-tag" style="margin-bottom:20px;">Riepilogo pagamento</div>

                {{-- Importo in evidenza --}}
                <div style="text-align:center;padding:24px 0;border-bottom:1px solid var(--border);margin-bottom:24px;">
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:6px;">Importo da pagare</div>
                    <div style="font-size:52px;font-weight:800;letter-spacing:-1.5px;line-height:1;">
                        {{ number_format($pr->amount, 2, ',', '.') }}
                        <span style="font-size:26px;font-weight:600;color:var(--text-muted);">KY</span>
                    </div>
                    @if($pr->description)
                        <div style="margin-top:10px;font-size:14px;color:var(--text-muted);font-style:italic;">"{{ $pr->description }}"</div>
                    @endif
                </div>

                {{-- Da → A --}}
                <div style="display:grid;gap:14px;margin-bottom:28px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px;background:var(--bg-subtle, rgba(0,0,0,.03));border-radius:10px;">
                        <div style="font-size:13px;color:var(--text-muted);">Da (tu)</div>
                        <div style="text-align:right;">
                            <div style="font-size:14px;font-weight:700;">{{ $fromAccount->company?->name ?? $fromAccount->display_name }}</div>
                            <div style="font-size:11px;color:var(--text-muted);font-family:monospace;">{{ $fromAccount->account_number }}</div>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                                Disponibile: <strong>{{ number_format($fromAccount->saldoDisponibile(), 2, ',', '.') }} KY</strong>
                            </div>
                        </div>
                    </div>

                    <div style="text-align:center;font-size:22px;color:var(--text-muted);">↓</div>

                    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px;background:rgba(109,40,217,.06);border-radius:10px;border:1px solid rgba(109,40,217,.15);">
                        <div style="font-size:13px;color:var(--text-muted);">A (commerciante)</div>
                        <div style="text-align:right;">
                            <div style="font-size:14px;font-weight:700;">{{ $pr->toAccount->company?->name ?? $pr->toAccount->display_name }}</div>
                            <div style="font-size:11px;color:var(--text-muted);font-family:monospace;">{{ $pr->toAccount->account_number }}</div>
                        </div>
                    </div>
                </div>

                {{-- Scadenza --}}
                <div style="font-size:12px;color:var(--text-muted);text-align:center;margin-bottom:20px;" id="expiry-note">
                    QR valido fino alle {{ $pr->expires_at->format('H:i:s') }}
                    (<span id="expiry-countdown">caricamento...</span>)
                </div>

                {{-- Pulsante paga --}}
                @if($fromAccount->saldoDisponibile() >= $pr->amount)
                    <form method="POST" action="{{ route('portal.pay-request.pay', $pr->token) }}" id="pay-form">
                        @csrf
                        <button
                            type="submit"
                            class="cta"
                            style="width:100%;justify-content:center;font-size:18px;padding:16px;"
                            id="pay-btn"
                            onclick="this.disabled=true;this.textContent='Pagamento in corso...';this.form.submit();"
                        >
                            Paga {{ number_format($pr->amount, 2, ',', '.') }} KY ora
                        </button>
                    </form>
                @else
                    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:14px;text-align:center;margin-bottom:12px;">
                        <strong style="color:#dc2626;">Saldo insufficiente</strong>
                        <div style="font-size:13px;color:#b91c1c;margin-top:4px;">
                            Il tuo saldo disponibile ({{ number_format($fromAccount->saldoDisponibile(), 2, ',', '.') }} KY) non copre l'importo richiesto.
                        </div>
                    </div>
                    <a href="{{ route('portal.dashboard') }}" class="cta secondary" style="width:100%;justify-content:center;">Torna al conto</a>
                @endif
            </section>
        @endif
    </div>

    {{-- Info laterale --}}
    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Pagamento sicuro</div>
            <div style="margin-top:14px;font-size:14px;color:var(--text-muted);line-height:1.7;">
                <p style="margin-bottom:10px;">Il pagamento avviene direttamente nel circuito KMoney. Nessun dato di carta o bancario coinvolto.</p>
                <p style="margin-bottom:10px;">Il QR e' monouso: dopo il pagamento non puo' essere riutilizzato.</p>
                <p>In caso di problemi puoi richiedere un rimborso dal tuo storico movimenti.</p>
            </div>
        </section>

        @unless($pr->isClosed())
        <section class="card light-card card-pad">
            <div class="k-tag">Saldo dopo il pagamento</div>
            <div style="margin-top:12px;">
                <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px;">
                    <span style="color:var(--text-muted);">Saldo attuale</span>
                    <strong>{{ number_format($fromAccount->saldoDisponibile(), 2, ',', '.') }} KY</strong>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px;">
                    <span style="color:var(--text-muted);">Importo</span>
                    <strong style="color:#dc2626;">− {{ number_format($pr->amount, 2, ',', '.') }} KY</strong>
                </div>
                <div style="border-top:1px solid var(--border);padding-top:8px;display:flex;justify-content:space-between;font-size:15px;">
                    <span style="font-weight:600;">Saldo residuo</span>
                    <strong style="{{ ($fromAccount->saldoDisponibile() - $pr->amount) < 0 ? 'color:#dc2626' : 'color:#059669' }}">
                        {{ number_format($fromAccount->saldoDisponibile() - $pr->amount, 2, ',', '.') }} KY
                    </strong>
                </div>
            </div>
        </section>
        @endunless
    </div>

</div>

@push('scripts')
<script>
(function () {
    const expiresAt = {{ $pr->expires_at->timestamp }};
    const el = document.getElementById('expiry-countdown');
    if (!el) return;

    function tick() {
        const left = Math.max(0, expiresAt - Math.floor(Date.now() / 1000));
        if (left <= 0) {
            el.textContent = 'scaduto';
            el.style.color = '#dc2626';
            const btn = document.getElementById('pay-btn');
            if (btn) { btn.disabled = true; btn.textContent = 'QR scaduto'; }
            return;
        }
        const mm = String(Math.floor(left / 60)).padStart(2, '0');
        const ss = String(left % 60).padStart(2, '0');
        el.textContent = 'scade in ' + mm + ':' + ss;
        if (left <= 60) el.style.color = '#dc2626';
        setTimeout(tick, 1000);
    }
    tick();
})();
</script>
@endpush
@endsection
