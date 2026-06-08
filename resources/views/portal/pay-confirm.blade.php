@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('portal.pay.form') }}">Modifica</a>
@endsection

@section('content')
    <div class="summary-grid">
        <section class="card account-hero card-pad">
            <span class="k-tag">Conto di addebito</span>
            <h1 style="position:relative;z-index:1;margin:16px 0 18px;">{{ $currentAccount->display_name }}</h1>
            <div class="metric">
                <div class="metric-label">Circuito</div>
                <div class="metric-value">{{ $currentAccount->currency_code }}</div>
            </div>
            <div class="metric">
                <div class="metric-label">Operazione</div>
                <div class="metric-value">Pagamento</div>
            </div>
            <div class="stat-note">Verifica i dati prima di confermare. L'operazione sarà immediata e irreversibile.</div>
        </section>

        <section class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Riepilogo</span>
                    <h3 class="section-title">Conferma pagamento</h3>
                </div>
                <span class="pill">KY transfer</span>
            </div>

            <div class="form-body">

                @if ($needsStepUp)
                    <div class="alert alert-warning" style="margin-bottom:20px;">
                        <strong>Verifica identità richiesta</strong><br>
                        L'importo supera la soglia di sicurezza. Prima di confermare devi autenticarti nuovamente.
                    </div>
                @endif

                {{-- Riepilogo dati --}}
                <div class="field-grid" style="pointer-events:none;opacity:{{ $needsStepUp ? '0.5' : '1' }};">
                    <div class="field">
                        <label>Destinatario</label>
                        <div class="input-static">{{ $toAccount?->display_name ?? '—' }}</div>
                    </div>
                    <div class="field">
                        <label>Numero conto</label>
                        <div class="input-static">{{ $toAccount?->ky_account_number ?? '—' }}</div>
                    </div>
                    <div class="field">
                        <label>Importo</label>
                        <div class="input-static" style="font-size:1.4rem;font-weight:700;color:var(--accent);">
                            {{ ky_format($preview['amount_cents']) }} KY
                        </div>
                    </div>
                    @if (!empty($preview['description']))
                        <div class="field">
                            <label>Causale</label>
                            <div class="input-static">{{ $preview['description'] }}</div>
                        </div>
                    @endif
                </div>

                {{-- Azioni --}}
                <div class="form-actions">
                    <a href="{{ route('portal.pay.form') }}" class="cta secondary">Annulla</a>

                    @if ($needsStepUp)
                        <a href="{{ route('portal.step-up.show') }}" class="cta">
                            Verifica identità
                        </a>
                    @else
                        <form method="post" action="{{ route('portal.pay.execute') }}">
                            @csrf
                            <button type="submit" class="cta">
                                Conferma pagamento
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </section>
    </div>
@endsection
