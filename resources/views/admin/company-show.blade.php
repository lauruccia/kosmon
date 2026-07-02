@extends('layouts.portal')

@section('content')
<section class="page-intro--row page-intro">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <a href="{{ route('admin.companies.index') }}" style="font-size:12px;color:var(--text-muted);text-decoration:none;display:flex;align-items:center;gap:4px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
            Directory aziende
        </a>
    </div>
    <h2 style="margin-top:6px;">{{ $company->name }}</h2>
    <p>Gestione azienda nel circuito — assegnazione broker, conto e movimenti.</p>
</section>

{{-- KPI strip --}}
<div class="kpi-strip" style="margin-bottom:20px;">
    <div class="kpi-card">
        <div class="kpi-label">Saldo attuale</div>
        @if($account)
        <div class="kpi-value {{ $account->available_balance >= 0 ? 'positive' : '' }}" style="{{ $account->available_balance < 0 ? 'color:#dc2626;' : '' }}">
            {{ ky_format($account->available_balance) }}
            <small style="font-size:13px;font-weight:600;">KY</small>
        </div>
        @else
        <div class="kpi-value" style="font-size:16px;color:var(--text-muted);">Nessun conto</div>
        @endif
        <div class="kpi-note">Saldo principale</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Fido</div>
        @if($account)
        @php $cl = $account->activeCreditLimit(); @endphp
        <div class="kpi-value" style="font-size:{{ $cl ? '22px' : '16px' }};">
            @if($cl)
                {{ ky_format($cl->credit_limit) }}
                <small style="font-size:13px;font-weight:600;">KY</small>
            @else
                <span style="color:var(--text-muted);">—</span>
            @endif
        </div>
        @else
        <div class="kpi-value" style="font-size:16px;color:var(--text-muted);">—</div>
        @endif
        <div class="kpi-note">Limite di credito attivo</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">KYC</div>
        <div class="kpi-value" style="font-size:15px;margin-top:6px;">
            <span class="chip {{ $company->kyc_status === 'approved' ? 'success' : ($company->kyc_status === 'rejected' ? 'pink' : '') }}">
                {{ $company->kyc_status_label }}
            </span>
        </div>
        <div class="kpi-note">Stato verifica</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Broker assegnato</div>
        <div class="kpi-value" style="font-size:15px;margin-top:4px;font-weight:700;">
            {{ $company->broker?->name ?? '—' }}
        </div>
        <div class="kpi-note">Operatore di riferimento</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Utenti</div>
        <div class="kpi-value">{{ $company->users->count() }}</div>
        <div class="kpi-note">Operatori registrati</div>
    </div>
</div>

{{-- ── Azioni rapide: Stato attivazione + Piano abbonamento ── --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

    {{-- Stato azienda --}}
    <section class="card light-card card-pad" style="padding:18px 20px;">
        <div class="eyebrow" style="margin-bottom:10px;">Stato nel circuito</div>
        @if(session('portal_success'))
            <div style="padding:9px 12px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;color:#065f46;font-size:13px;margin-bottom:12px;">
                {{ session('portal_success') }}
            </div>
        @endif
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            @php
                $isActive = $company->status === 'active' && !$company->isSuspended();
            @endphp
            <span style="display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:700;
                         padding:6px 14px;border-radius:999px;
                         background:{{ $isActive ? '#d1fae5' : '#fef3c7' }};
                         color:{{ $isActive ? '#065f46' : '#92400e' }};
                         border:1.5px solid {{ $isActive ? '#6ee7b7' : '#fcd34d' }};">
                <span style="width:8px;height:8px;border-radius:50%;background:{{ $isActive ? '#10b981' : '#f59e0b' }};flex-shrink:0;"></span>
                {{ $isActive ? 'Attiva' : ($company->status === 'suspended' ? 'Sospesa' : 'Non attiva') }}
            </span>

            @if($isActive)
                <form method="POST" action="{{ route('admin.companies.deactivate', $company) }}" style="margin:0;">
                    @csrf
                    <button type="submit" style="padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:700;
                                                  cursor:pointer;border:1.5px solid #fca5a5;
                                                  background:#fff;color:#b91c1c;"
                            onclick="return confirm('Disattivare {{ addslashes($company->name) }}?')">
                        Disattiva
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.companies.activate', $company) }}" style="margin:0;">
                    @csrf
                    <button type="submit" style="padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:700;
                                                  cursor:pointer;border:1.5px solid #6ee7b7;
                                                  background:#059669;color:#fff;">
                        Attiva nel circuito
                    </button>
                </form>
            @endif

            @if($company->isSuspended())
                <form method="POST" action="{{ route('admin.companies.unsuspend', $company) }}" style="margin:0;">
                    @csrf
                    <button type="submit" style="padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:700;
                                                  cursor:pointer;border:1.5px solid #d1d5db;
                                                  background:#fff;color:#374151;">
                        Rimuovi sospensione
                    </button>
                </form>
            @endif
        </div>
        @if($company->isSuspended() && $company->suspension_reason)
            <p style="margin:10px 0 0;font-size:12px;color:#b91c1c;">
                Motivo sospensione: {{ $company->suspension_reason }}
            </p>
        @endif
    </section>

    {{-- Piano abbonamento --}}
    <section class="card light-card card-pad" style="padding:18px 20px;">
        <div class="eyebrow" style="margin-bottom:10px;">Piano abbonamento</div>
        <form method="POST" action="{{ route('admin.companies.plan', $company) }}"
              style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            @csrf
            @php
                $planColors = [
                    'ecommerce'  => ['bg'=>'#faf5ff','border'=>'#d8b4fe','text'=>'#6b21a8'],
                    'vetrina'    => ['bg'=>'#eff6ff','border'=>'#93c5fd','text'=>'#1d4ed8'],
                    'biglietto'  => ['bg'=>'#ecfdf5','border'=>'#6ee7b7','text'=>'#065f46'],
                    'anagrafica' => ['bg'=>'#f9fafb','border'=>'#d1d5db','text'=>'#374151'],
                ];
                $currentPlan = $company->subscription_plan;
                $pc = $planColors[$currentPlan] ?? null;
            @endphp
            @if($pc)
                <span style="padding:5px 14px;border-radius:999px;font-size:12px;font-weight:700;
                              background:{{ $pc['bg'] }};border:1.5px solid {{ $pc['border'] }};color:{{ $pc['text'] }};">
                    {{ $company->subscription_plan_label }}
                </span>
            @else
                <span style="padding:5px 14px;border-radius:999px;font-size:12px;font-weight:700;
                              background:#f3f4f6;border:1.5px solid #e5e7eb;color:#9ca3af;">
                    Nessun piano
                </span>
            @endif
            <select name="subscription_plan"
                    style="padding:7px 12px;border:1.5px solid var(--line);border-radius:8px;font-size:13px;
                           background:#fff;color:var(--ink);flex:1;min-width:150px;">
                <option value="">— Nessun piano —</option>
                @foreach(\App\Models\Company::SUBSCRIPTION_PLANS as $key => $label)
                    <option value="{{ $key }}" @selected($currentPlan === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit"
                    style="padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:700;
                           cursor:pointer;border:1.5px solid #0c4a86;background:#0c4a86;color:#fff;white-space:nowrap;">
                Salva piano
            </button>
        </form>
        <p style="margin:10px 0 0;font-size:11.5px;color:var(--ink-muted);">
            Ecommerce › Vetrina › Biglietto › Anagrafica — ordine di visibilità in directory.
        </p>
    </section>
</div>

<div class="portal-grid" style="--grid-cols:2;">

    {{-- Colonna sinistra --}}
    <div class="stack">

        {{-- Assegnazione broker --}}
        <section class="card light-card card-pad">
            <div class="eyebrow" style="margin-bottom:12px;">Assegna broker</div>

            @if(session('portal_success'))
                <div style="padding:10px 14px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;color:#065f46;font-size:13px;margin-bottom:14px;">
                    {{ session('portal_success') }}
                </div>
            @endif

            <form method="POST" action="{{ route('admin.companies.broker', $company) }}">
                @csrf
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:var(--text);">
                        Operatore broker
                    </label>
                    <select name="broker_user_id"
                        style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--surface);color:var(--text);">
                        <option value="">— Nessun broker assegnato —</option>
                        @foreach($brokerUsers as $bu)
                            <option value="{{ $bu->id }}" {{ $company->broker_user_id == $bu->id ? 'selected' : '' }}>
                                {{ $bu->name }} ({{ $bu->email }})
                                @if($bu->is_super_admin) [Admin] @elseif($bu->role === 'broker') [Broker] @endif
                            </option>
                        @endforeach
                    </select>
                    <p style="font-size:11px;color:var(--text-muted);margin-top:5px;">
                        Solo utenti con ruolo <strong>broker</strong> o amministratori sono mostrati.
                    </p>
                </div>
                <button type="submit" class="cta" style="width:100%;justify-content:center;">
                    Salva assegnazione
                </button>
            </form>
        </section>

        {{-- Dati azienda --}}
        <section class="card light-card card-pad">
            <div class="eyebrow" style="margin-bottom:10px;">Dati azienda</div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <tr>
                    <td style="padding:6px 0;color:var(--text-muted);width:38%;">Ragione sociale</td>
                    <td style="padding:6px 0;font-weight:600;">{{ $company->name }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:var(--text-muted);">Settore</td>
                    <td style="padding:6px 0;">{{ $company->sector ?? '—' }}</td>
                </tr>
                @if($company->vat_number)
                <tr>
                    <td style="padding:6px 0;color:var(--text-muted);">P. IVA</td>
                    <td style="padding:6px 0;font-family:monospace;">{{ $company->vat_number }}</td>
                </tr>
                @endif
                @if($company->email)
                <tr>
                    <td style="padding:6px 0;color:var(--text-muted);">Email</td>
                    <td style="padding:6px 0;"><a href="mailto:{{ $company->email }}" style="color:var(--primary);">{{ $company->email }}</a></td>
                </tr>
                @endif
                @if($company->phone)
                <tr>
                    <td style="padding:6px 0;color:var(--text-muted);">Telefono</td>
                    <td style="padding:6px 0;">{{ $company->phone }}</td>
                </tr>
                @endif
                <tr>
                    <td style="padding:6px 0;color:var(--text-muted);">Stato</td>
                    <td style="padding:6px 0;">
                        <span class="chip {{ $company->status === 'active' ? 'success' : 'pink' }}">
                            {{ $company->status === 'active' ? 'Attiva' : 'Sospesa' }}
                        </span>
                    </td>
                </tr>
                @if($account)
                <tr>
                    <td style="padding:6px 0;color:var(--text-muted);">N° conto</td>
                    <td style="padding:6px 0;font-family:monospace;font-size:12px;">{{ $account->account_number }}</td>
                </tr>
                @endif
            </table>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--line);">
                <a href="{{ route('admin.kyc.show', $company) }}" class="cta secondary" style="font-size:12px;min-height:32px;">KYC</a>
                @if($account)
                    <a href="{{ route('admin.accounts.show', $account) }}" class="cta secondary" style="font-size:12px;min-height:32px;">Conto</a>
                    <a href="{{ route('admin.accounts.statement', $account) }}" class="cta secondary" style="font-size:12px;min-height:32px;">Estratto conto PDF</a>
                @endif
            </div>
        </section>

        {{-- Link NFC statico esercente --}}
        @if($staticNfcUrl)
        <section class="card light-card card-pad">
            <div class="eyebrow" style="margin-bottom:10px;">Link NFC statico (esercente)</div>
            <p style="font-size:12px;color:var(--text-muted);margin:0 0 12px;">
                Scrivi questo URL su un tag NFC vuoto (NTAG) con un'app tipo "NFC Tools" e consegna la card
                all'esercente. Il cliente avvicina il telefono, inserisce l'importo e paga direttamente su questo conto.
            </p>
            <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                <div style="background:#fff;padding:10px;border:1px solid var(--line);border-radius:10px;flex-shrink:0;">
                    {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(120)->errorCorrection('H')->generate($staticNfcUrl) !!}
                </div>
                <div style="flex:1;min-width:220px;">
                    <div style="display:flex;gap:8px;align-items:center;background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:10px 14px;">
                        <span id="static-nfc-url" style="flex:1;font-size:12px;font-family:monospace;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;">{{ $staticNfcUrl }}</span>
                        <button type="button" class="cta secondary" style="padding:6px 12px;font-size:12px;min-height:auto;" onclick="copyStaticNfcUrl(this)">Copia</button>
                    </div>
                    <p style="font-size:11px;color:var(--text-muted);margin:8px 0 0;">
                        Inquadra il QR con lo smartphone per verificare che il link porti al form di pagamento corretto prima di scrivere la card.
                    </p>
                </div>
            </div>

            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
                <div id="nfc-write-bar" style="background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;color:var(--text-muted);text-align:center;margin-bottom:10px;">
                    Avvicina un tag NFC vuoto e premi il pulsante per scriverlo direttamente
                </div>
                <button type="button" id="nfc-write-btn" class="cta" style="width:100%;" onclick="writeStaticNfcTag(this)">
                    📶 Scrivi tag NFC ora
                </button>
                <p style="font-size:11px;color:var(--text-muted);margin:8px 0 0;">
                    Funziona solo da <strong>Chrome su Android</strong> con NFC attivo (Web NFC API) — apri questa pagina dal telefono che userai per scrivere le card. Su desktop/iPhone usa il link o il QR con un'app tipo "NFC Tools".
                </p>
            </div>
        </section>
        <script>
        function copyStaticNfcUrl(btn) {
            var url = document.getElementById('static-nfc-url').textContent.trim();
            navigator.clipboard.writeText(url).then(function () {
                var orig = btn.textContent;
                btn.textContent = '✓ Copiato';
                setTimeout(function () { btn.textContent = orig; }, 1800);
            });
        }

        async function writeStaticNfcTag(btn) {
            var bar = document.getElementById('nfc-write-bar');
            var url = document.getElementById('static-nfc-url').textContent.trim();

            if (!('NDEFReader' in window)) {
                bar.textContent = 'NFC non disponibile su questo browser. Apri questa pagina da Chrome su Android.';
                bar.style.color = '#b91c1c';
                return;
            }

            btn.disabled = true;
            bar.textContent = 'Avvicina il tag NFC vuoto al telefono...';
            bar.style.color = 'var(--text)';

            try {
                var ndef = new NDEFReader();
                await ndef.write({ records: [{ recordType: 'url', data: url }] });

                bar.textContent = '✓ Tag scritto con successo! Consegna la card all\'esercente.';
                bar.style.background = '#dcfce7';
                bar.style.color = '#166534';
                bar.style.border = '1px solid #bbf7d0';
            } catch (err) {
                bar.textContent = 'Errore: ' + (err.message || err.name) + '. Riprova.';
                bar.style.color = '#b91c1c';
            }
            btn.disabled = false;
        }
        </script>
        @endif

        {{-- Utenti --}}
        <section class="card light-card card-pad">
            <div class="eyebrow" style="margin-bottom:10px;">Utenti associati</div>
            @if($company->users->isEmpty())
                <p class="table-muted" style="font-size:13px;">Nessun utente registrato.</p>
            @else
                @foreach($company->users as $u)
                <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--line);font-size:13px;">
                    <div>
                        <strong>{{ $u->name }}</strong>
                        <div class="table-muted" style="font-size:11px;">{{ $u->email }}</div>
                    </div>
                    <a href="{{ route('admin.users.show', $u) }}" class="cta secondary" style="font-size:11px;min-height:26px;padding:0 10px;">Profilo</a>
                </div>
                @endforeach
            @endif
        </section>

        {{-- Limite di credito --}}
        @if($account)
        <section class="card light-card card-pad">
            <div class="eyebrow" style="margin-bottom:10px;">Limite di credito (Fido)</div>

            @php $activeLimit = $account->activeCreditLimit(); @endphp

            @if($activeLimit)
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">
                <div style="text-align:center;padding:12px 8px;background:var(--surface);border:1px solid var(--line);border-radius:10px;">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Fido circuito</div>
                    <div style="font-size:18px;font-weight:800;color:var(--primary);">{{ ky_format($activeLimit->credit_limit) }}</div>
                    <div style="font-size:10px;color:var(--text-muted);font-weight:700;">KY</div>
                </div>
                <div style="text-align:center;padding:12px 8px;background:var(--surface);border:1px solid var(--line);border-radius:10px;">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Limite giornaliero</div>
                    <div style="font-size:18px;font-weight:800;">{{ $activeLimit->daily_outgoing_limit ? ky_format($activeLimit->daily_outgoing_limit) : '—' }}</div>
                    <div style="font-size:10px;color:var(--text-muted);font-weight:700;">KY</div>
                </div>
                <div style="text-align:center;padding:12px 8px;background:var(--surface);border:1px solid var(--line);border-radius:10px;">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Limite singolo</div>
                    <div style="font-size:18px;font-weight:800;">{{ $activeLimit->single_transfer_limit ? ky_format($activeLimit->single_transfer_limit) : '—' }}</div>
                    <div style="font-size:10px;color:var(--text-muted);font-weight:700;">KY</div>
                </div>
            </div>
            <p style="font-size:11px;color:var(--text-muted);margin-bottom:14px;">
                Impostato il {{ $activeLimit->approved_at?->format('d/m/Y H:i') ?? '—' }}
            </p>
            @else
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">
                Nessun limite impostato. Il conto opera con le soglie di default del circuito.
            </p>
            @endif

            <form method="POST" action="{{ route('admin.companies.credit-limit', $company) }}">
                @csrf
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px;">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;margin-bottom:5px;color:var(--text);">Fido circuito (KY) *</label>
                        <input type="number" name="credit_limit" min="0" step="0.01"
                            value="{{ old('credit_limit', ky_input($activeLimit?->credit_limit)) }}"
                            required
                            style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--surface);color:var(--text);">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;margin-bottom:5px;color:var(--text);">Limite giornaliero (KY)</label>
                        <input type="number" name="daily_outgoing_limit" min="0" step="0.01"
                            value="{{ old('daily_outgoing_limit', ky_input($activeLimit?->daily_outgoing_limit)) }}"
                            placeholder="—"
                            style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--surface);color:var(--text);">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;margin-bottom:5px;color:var(--text);">Limite per movimento (KY)</label>
                        <input type="number" name="single_transfer_limit" min="0" step="0.01"
                            value="{{ old('single_transfer_limit', ky_input($activeLimit?->single_transfer_limit)) }}"
                            placeholder="—"
                            style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--surface);color:var(--text);">
                    </div>
                </div>
                <button type="submit" class="cta" style="width:100%;justify-content:center;font-size:13px;min-height:36px;">
                    {{ $activeLimit ? 'Aggiorna limite' : 'Imposta limite' }}
                </button>
            </form>
        </section>
        @endif


    </div>

    {{-- Colonna destra: movimenti --}}
    <div class="stack">
        <section class="card" style="padding:0;overflow:hidden;">
            <div style="padding:12px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
                <div class="card-title">Ultimi movimenti</div>
                @if($account)
                <span class="table-muted" style="font-size:12px;">{{ $recentTransfers->count() }} recenti</span>
                @endif
            </div>

            @if(!$account)
                <div style="padding:36px;text-align:center;color:var(--text-muted);">
                    <div style="font-size:28px;margin-bottom:8px;">🏦</div>
                    <strong>Nessun conto attivo per questa azienda.</strong>
                </div>
            @elseif($recentTransfers->isEmpty())
                <div style="padding:36px;text-align:center;color:var(--text-muted);">
                    <div style="font-size:28px;margin-bottom:8px;">📭</div>
                    <strong>Nessun movimento registrato.</strong>
                </div>
            @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Controparte</th>
                        <th>Tipo</th>
                        <th>Operato da</th>
                        <th style="text-align:right;">Importo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentTransfers as $t)
                        @php
                            $isOut = $t->from_account_id === $account->id;
                            $cp = $isOut ? $t->toAccount : $t->fromAccount;
                        @endphp
                        <tr>
                            <td style="white-space:nowrap;font-size:12px;">
                                {{ optional($t->booked_at)->format('d/m/Y') }}
                            </td>
                            <td>
                                <strong style="font-size:13px;">{{ $cp?->display_name ?? '—' }}</strong>
                                <div class="table-muted" style="font-size:11px;">{{ $t->description ?: 'Movimento circuito' }}</div>
                            </td>
                            <td>
                                <span class="chip {{ $t->kind === 'broker_payment' ? '' : ($isOut ? 'pink' : 'success') }}" style="font-size:10px;">
                                    {{ $t->kind === 'broker_payment' ? 'Broker' : ($isOut ? 'Uscita' : 'Entrata') }}
                                </span>
                            </td>
                            <td style="font-size:12px;color:var(--text-muted);">
                                {{ $t->initiator?->name ?? '—' }}
                            </td>
                            <td style="text-align:right;white-space:nowrap;">
                                <strong style="color:{{ $isOut ? '#dc2626' : 'var(--teal-strong)' }};">
                                    {{ $isOut ? '-' : '+' }}{{ ky_format($t->amount) }}
                                </strong>
                                <div style="font-size:10px;color:var(--text-muted);font-weight:700;">KY</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </section>
    </div>


        {{-- ── Tetto massimo (max_balance) ──────────────────────────────── --}}
        @php
            $mainAccount = $company->accounts()->whereNull('parent_account_id')->where('status', 'active')->first();
            $commercialBadge = $mainAccount?->commercialStatusBadge() ?? ['label' => 'N/A', 'color' => 'gray'];
            $badgeBg = match($commercialBadge['color']) {
                'green'  => '#dcfce7', 'yellow' => '#fef9c3', 'red' => '#fee2e2', default => '#f3f4f6'
            };
            $badgeText = match($commercialBadge['color']) {
                'green'  => '#166534', 'yellow' => '#713f12', 'red' => '#991b1b', default => '#374151'
            };
        @endphp
        <section class="card card-pad">
            <div class="eyebrow" style="margin-bottom:12px;">Limiti conto — regole commerciali</div>

            {{-- Badge stato commerciale --}}
            @if($mainAccount)
            <div style="display:inline-block;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700;
                        background:{{ $badgeBg }};color:{{ $badgeText }};margin-bottom:16px;">
                {{ $commercialBadge['label'] }}
            </div>

            {{-- KPI saldo + tetto --}}
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;">
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px;">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:4px;">Saldo attuale</div>
                    <div style="font-size:18px;font-weight:800;color:{{ $mainAccount->available_balance < 0 ? '#dc2626' : 'var(--primary)' }};">
                        {{ ky_format($mainAccount->available_balance) }} KY
                    </div>
                </div>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px;">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:4px;">Tetto massimo</div>
                    <div style="font-size:18px;font-weight:800;color:var(--primary);">
                        {{ $mainAccount->max_balance !== null ? ky_format($mainAccount->max_balance) . ' KY' : '—' }}
                    </div>
                </div>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px;">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:4px;">Massimale (fido)</div>
                    <div style="font-size:18px;font-weight:800;color:var(--primary);">
                        {{ ky_format($mainAccount->massimale()) }} KY
                    </div>
                </div>
            </div>

            {{-- Regola attiva --}}
            <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:12px;color:var(--text-muted);">
                @if($mainAccount->isAtCeiling())
                    <strong style="color:#dc2626;">Tetto raggiunto:</strong> l'azienda può solo acquistare. Per riabilitare la vendita abbassa il tetto o attendi che l'azienda spenda KY.
                @elseif($mainAccount->isInDebit())
                    <strong style="color:#b45309;">Saldo negativo:</strong> l'azienda può vendere solo al <strong>100% KY</strong> finché il saldo torna positivo.
                @else
                    <strong style="color:#15803d;">Saldo positivo:</strong> l'azienda può scegliere liberamente il mix KY/EUR (0%, 25%, 50%, 75%, 100%).
                @endif
            </div>

            {{-- Form imposta tetto --}}
            <form method="POST" action="{{ route('admin.companies.max-balance', $company) }}">
                @csrf
                <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:flex-end;">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;margin-bottom:5px;color:var(--text);">
                            Tetto massimo conto (KY)
                        </label>
                        <input type="number" name="max_balance" min="0" step="0.01"
                            value="{{ old('max_balance', ky_input($mainAccount->max_balance)) }}"
                            placeholder="Lascia vuoto per nessun tetto"
                            class="form-input" style="width:100%;">
                        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                            Quando il saldo raggiunge questo valore l'azienda passa in modalità "solo acquisto".
                        </div>
                    </div>
                    <button type="submit" class="cta" style="white-space:nowrap;">Aggiorna tetto</button>
                </div>
            </form>
            @else
                <p style="font-size:13px;color:var(--text-muted);">Nessun conto principale trovato per questa azienda.</p>
            @endif
        </section>

        {{-- ── Sospensione account ─────────────────────────────────────────── --}}
        <section class="card card-pad" style="border: 1.5px solid {{ $company->isSuspended() ? '#fca5a5' : 'var(--border)' }};">
            <div class="eyebrow" style="margin-bottom:12px;color:{{ $company->isSuspended() ? '#dc2626' : 'inherit' }};">
                {{ $company->isSuspended() ? '🔴 Account sospeso' : '🟢 Stato account' }}
            </div>

            @if($company->isSuspended())
                <div style="background:#fef2f2;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
                    <div style="font-size:13px;font-weight:700;color:#991b1b;">Sospesa il {{ $company->suspended_at->format('d/m/Y H:i') }}</div>
                    @if($company->suspension_reason)
                        <div style="font-size:13px;color:#b91c1c;margin-top:4px;">Motivo: {{ $company->suspension_reason }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.companies.unsuspend', $company) }}">
                    @csrf
                    <button type="submit" class="cta" style="background:#16a34a;" onclick="return confirm('Riattivare questa azienda?')">
                        Rimuovi sospensione
                    </button>
                </form>
            @else
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">
                    L'azienda è attiva. Puoi sospenderla per bloccare l'accesso al portale a tutti gli utenti associati.
                </p>
                <form method="POST" action="{{ route('admin.companies.suspend', $company) }}">
                    @csrf
                    <div style="margin-bottom:10px;">
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Motivo sospensione (opzionale)</label>
                        <input type="text" name="suspension_reason" class="form-input" placeholder="Es: verifica frode, KYC scaduto…" style="width:100%;">
                    </div>
                    <button type="submit" class="cta" style="background:#dc2626;" onclick="return confirm('Sospendere questa azienda? Gli utenti verranno disconnessi.')">
                        Sospendi account
                    </button>
                </form>
            @endif
        </section>

        {{-- ── Zona pericolosa: eliminazione dati di test ──────────────────── --}}
        @if(auth()->user()->is_super_admin)
        <section class="card card-pad" style="border: 1.5px solid #fca5a5;">
            <div class="eyebrow" style="margin-bottom:8px;color:#dc2626;">⚠️ Zona pericolosa</div>
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">
                Elimina definitivamente e fisicamente questa azienda: tutti i suoi conti, utenti e movimenti
                (comprese le ricadute sui saldi delle controparti reali coinvolte). Pensata per ripulire
                <strong>account/movimenti di prova</strong>, non per uso ordinario. Operazione irreversibile.
            </p>
            <a href="{{ route('admin.companies.purge-test', $company) }}" class="cta" style="background:#dc2626;">
                Elimina definitivamente (dati di test)
            </a>
        </section>
        @endif

</div>
@endsection
