@extends('layouts.portal')

@section('content')
    {{-- KPI strip: 3 stat + 1 azione --}}
    <section class="hero-strip" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:16px;">
        <article class="stat-card">
            <div class="eyebrow">Override utenti</div>
            <div class="section-title">{{ $usersWithOverridesCount }}</div>
            <div class="table-muted">Con limiti personalizzati</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Massimale / fido default</div>
            <div class="section-title">{{ ky_format((int) ($defaultTransferLimits['negative_balance_limit'] ?? 0)) }} KY</div>
            <div class="table-muted">Credito spendibile di default</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Limite mensile default</div>
            <div class="section-title">{{ $defaultTransferLimits['monthly_transaction_limit'] !== null ? ky_format($defaultTransferLimits['monthly_transaction_limit']) . ' KY' : '—' }}</div>
            <div class="table-muted">Soglia mensile uscite</div>
        </article>
        <article class="stat-card" style="display:flex;flex-direction:column;justify-content:center;align-items:flex-start;gap:8px;">
            <div class="eyebrow">Gestione utenti</div>
            <a class="cta secondary" href="{{ route('admin.users.index') }}" style="margin-top:4px;">Apri utenti</a>
        </article>
    </section>

    <section class="summary-grid">
        {{-- Valori correnti --}}
        <article class="card light-card">
            <div class="section-head">
                <div><span class="eyebrow">Regole ereditate</span><h3 class="section-title">Valori effettivi di default</h3></div>
                <span class="pill">fallback globale</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:4px;">
                <div class="timeline-item" style="padding:10px;">
                    <strong style="font-size:12px;">Massimale consentito</strong>
                    <div class="table-muted" style="font-size:11px;">Fido max in negativo.</div>
                    <div class="section-title" style="font-size:18px;">-{{ ky_format((int) ($defaultTransferLimits['negative_balance_limit'] ?? 0)) }} KY</div>
                </div>
                <div class="timeline-item" style="padding:10px;">
                    <strong style="font-size:12px;">Limite giornaliero</strong>
                    <div class="table-muted" style="font-size:11px;">Max uscite per giorno.</div>
                    <div class="section-title" style="font-size:18px;">{{ $defaultTransferLimits['daily_transaction_limit'] !== null ? ky_format($defaultTransferLimits['daily_transaction_limit']) . ' KY' : 'Non impostato' }}</div>
                </div>
                <div class="timeline-item" style="padding:10px;">
                    <strong style="font-size:12px;">Limite mensile</strong>
                    <div class="table-muted" style="font-size:11px;">Max uscite nel mese.</div>
                    <div class="section-title" style="font-size:18px;">{{ $defaultTransferLimits['monthly_transaction_limit'] !== null ? ky_format($defaultTransferLimits['monthly_transaction_limit']) . ' KY' : 'Non impostato' }}</div>
                </div>
                <div class="timeline-item" style="padding:10px;">
                    <strong style="font-size:12px;">Limite per movimento</strong>
                    <div class="table-muted" style="font-size:11px;">Max per singola operazione.</div>
                    <div class="section-title" style="font-size:18px;">{{ $defaultTransferLimits['per_movement_limit'] !== null ? ky_format($defaultTransferLimits['per_movement_limit']) . ' KY' : 'Non impostato' }}</div>
                </div>
            </div>
            <div class="notice" style="margin-top:14px;font-size:12px;">
                La disponibilità commerciale è calcolata automaticamente: <strong>saldo positivo + fido attivo</strong>. Non è impostabile qui.
            </div>
        </article>

        {{-- Form aggiornamento --}}
        <article class="card light-card" id="limits-default-form">
            <div class="section-head">
                <div><span class="eyebrow">Configurazione admin</span><h3 class="section-title">Aggiorna valori di default</h3></div>
                <span class="pill warn">0 = nessun fido</span>
            </div>
            <div class="notice" style="margin-bottom:14px;">
                I nuovi default valgono per i prossimi utenti. Gli utenti già registrati mantengono i valori effettivi attuali.
            </div>
            <form method="post" action="{{ route('admin.limits.update') }}" class="field-grid">
                @csrf
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div class="field">
                        <label>Massimale / fido (KY)</label>
                        <input type="number" min="0" step="0.01" name="default_negative_balance_limit" value="{{ old('default_negative_balance_limit', ky_input($defaultTransferLimits['negative_balance_limit'])) }}">
                    </div>
                    <div class="field">
                        <label>Limite per movimento (KY)</label>
                        <input type="number" min="0" step="0.01" name="default_per_movement_limit" value="{{ old('default_per_movement_limit', ky_input($defaultTransferLimits['per_movement_limit'])) }}">
                    </div>
                    <div class="field">
                        <label>Limite giornaliero (KY)</label>
                        <input type="number" min="0" step="0.01" name="default_daily_transaction_limit" value="{{ old('default_daily_transaction_limit', ky_input($defaultTransferLimits['daily_transaction_limit'])) }}">
                    </div>
                    <div class="field">
                        <label>Limite mensile (KY)</label>
                        <input type="number" min="0" step="0.01" name="default_monthly_transaction_limit" value="{{ old('default_monthly_transaction_limit', ky_input($defaultTransferLimits['monthly_transaction_limit'])) }}">
                    </div>
                    <div class="field" style="grid-column:1/-1;">
                        <label>Soglia conferma identità (TOTP/step-up) per pagamento diretto (KY)</label>
                        <input type="number" min="0" step="0.01" name="payment_confirm_totp_threshold" value="{{ old('payment_confirm_totp_threshold', ky_input($defaultTransferLimits['payment_confirm_totp_threshold'])) }}">
                        <small style="color:var(--text-muted);">Se compilato, i pagamenti diretti sopra questa soglia richiedono una verifica identità aggiuntiva prima di essere eseguiti. Lasciare vuoto per disabilitare.</small>
                    </div>
                </div>
                <div class="form-actions" style="justify-content:flex-start;margin-top:6px;">
                    <button type="submit" class="cta">Salva default</button>
                </div>
            </form>
        </article>
    </section>
@endsection
