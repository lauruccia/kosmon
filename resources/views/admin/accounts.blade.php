@extends('layouts.portal')

@section('content')
@php
    $totalBalance      = $accounts->sum('available_balance');
    $activeAccounts    = $accounts->where('status', 'active')->count();
    $suspendedAccounts = $accounts->where('status', 'suspended')->count();
    $companyAccounts   = $accounts->where('owner_type', 'company')->count();
    $privateAccounts   = $accounts->where('owner_type', 'private')->count();
    $negativeAccounts  = $accounts->where('available_balance', '<', 0)->count();
@endphp

{{-- ─── KPI strip (5 colonne: 4 metriche + azioni rapide) ─────── --}}
<section class="hero-strip" style="grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:20px;">

    <article class="stat-card">
        <div class="eyebrow">Conti nel circuito</div>
        <div class="section-title">{{ $accounts->count() }}</div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:6px;">
            <span class="chip success">{{ $activeAccounts }} attivi</span>
            @if($suspendedAccounts > 0)
                <span class="chip pink">{{ $suspendedAccounts }} sospesi</span>
            @endif
        </div>
    </article>

    <article class="stat-card">
        <div class="eyebrow">Saldo netto circolante</div>
        <div class="section-title" style="font-size:20px;color:{{ $totalBalance >= 0 ? 'var(--teal)' : 'var(--danger)' }};">
            {{ ky_format($totalBalance) }} KY
        </div>
        <div class="table-muted" style="margin-top:4px;">Somma saldi contabili</div>
    </article>

    <article class="stat-card">
        <div class="eyebrow">Aziende / Privati</div>
        <div class="section-title">{{ $companyAccounts }} / {{ $privateAccounts }}</div>
        <div class="table-muted" style="margin-top:4px;">Distribuzione profili circuito</div>
    </article>

    <article class="stat-card">
        <div class="eyebrow">Conti in debito</div>
        <div class="section-title" style="color:{{ $negativeAccounts > 0 ? 'var(--danger)' : 'var(--success)' }};">
            {{ $negativeAccounts }}
        </div>
        <div class="table-muted" style="margin-top:4px;">
            {{ $negativeAccounts === 0 ? 'Nessun conto negativo' : 'Saldo sotto zero' }}
        </div>
    </article>

    {{-- Azioni rapide --}}
    <article class="stat-card" style="display:flex;flex-direction:column;justify-content:center;gap:10px;background:var(--navy);border-color:var(--navy-mid);">
        <div class="eyebrow" style="color:rgba(255,255,255,.5);">Azioni rapide</div>
        <a class="cta" href="{{ route('admin.transfers.index') }}"
           style="text-align:center;background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.2);color:#fff;font-size:12px;">
            Movimenti
        </a>
        <a class="cta secondary" href="{{ route('admin.users.index') }}"
           style="text-align:center;background:transparent;border-color:rgba(255,255,255,.25);color:rgba(255,255,255,.8);font-size:12px;">
            Utenti
        </a>
    </article>

</section>

{{-- ─── Barra filtri ────────────────────────────────────────────── --}}
<section style="background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:12px 16px;margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:var(--shadow-xs);">

    {{-- Ricerca --}}
    <div style="flex:1;min-width:200px;position:relative;">
        <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--ink-muted);pointer-events:none;" width="14" height="14" viewBox="0 0 20 20" fill="none">
            <circle cx="8.5" cy="8.5" r="5.75" stroke="currentColor" stroke-width="1.8"/>
            <path d="M13.5 13.5L17 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
        <input id="ac-search" type="text" placeholder="Cerca per nome, numero, azienda…"
               style="width:100%;padding:8px 10px 8px 32px;border:1px solid var(--line);border-radius:8px;background:var(--surface-soft);color:var(--ink);font-size:13px;outline:none;transition:border-color .15s;"
               onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--line)'">
    </div>

    {{-- Stato --}}
    <div style="display:flex;align-items:center;gap:6px;">
        <label style="font-size:11px;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;">Stato</label>
        <select id="ac-filter-status" class="ac-select">
            <option value="all">Tutti</option>
            <option value="active">Attivi</option>
            <option value="suspended">Sospesi</option>
        </select>
    </div>

    {{-- Profilo --}}
    <div style="display:flex;align-items:center;gap:6px;">
        <label style="font-size:11px;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;">Profilo</label>
        <select id="ac-filter-profile" class="ac-select">
            <option value="all">Tutti</option>
            <option value="company">Aziende</option>
            <option value="private">Privati</option>
        </select>
    </div>

    {{-- Saldo --}}
    <div style="display:flex;align-items:center;gap:6px;">
        <label style="font-size:11px;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;">Saldo</label>
        <select id="ac-filter-balance" class="ac-select">
            <option value="all">Tutti i saldi</option>
            <option value="positive">Positivo (&gt; 0)</option>
            <option value="negative">Negativo (&lt; 0)</option>
            <option value="zero">Zero esatto</option>
            <option value="with_fido">Con massimale attivo</option>
            <option value="near_min">Vicino al limite minimo (&gt;80% fido usato)</option>
            <option value="near_max">Vicino al tetto massimo (&gt;80% tetto)</option>
            <option value="over_max">Sopra il tetto massimo</option>
        </select>
    </div>

    <span id="ac-count" style="font-size:12px;color:var(--ink-muted);white-space:nowrap;margin-left:auto;"></span>
</section>

{{-- ─── Tabella conti ───────────────────────────────────────────── --}}
<section class="card light-card" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
        <table class="admin-table" id="ac-table" style="min-width:880px;">
            <thead>
                <tr>
                    <th class="ac-th" data-col="name" style="padding-left:18px;">Conto <span class="ac-sort-icon">&#8597;</span></th>
                    <th class="ac-th" data-col="email">Email <span class="ac-sort-icon">&#8597;</span></th>
                    <th>Tipo</th>
                    <th>Stato</th>
                    <th class="ac-th" data-col="balance" style="text-align:right;">Saldo <span class="ac-sort-icon">&#8597;</span></th>
                    <th style="text-align:right;">Disponibile</th>
                    <th>Stato commerciale</th>
                    <th class="ac-sticky-col" style="width:84px;"></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($accounts as $account)
            @php
                $massimale        = $account->massimale();
                $saldoDisponibile = $account->saldoDisponibile();
                $balance                  = $account->available_balance;
                $maxBalance               = $account->max_balance;
                $ownerEmail               = $account->ownerUser?->email ?? ($account->company?->users->first()?->email ?? '—');
                $searchIndex              = strtolower($account->display_name . ' ' . $account->account_number . ' ' . $ownerEmail);
            @endphp
            <tr class="ac-row"
                data-name="{{ $searchIndex }}"
                data-status="{{ $account->status }}"
                data-owner-type="{{ $account->owner_type }}"
                data-balance="{{ $balance }}"
                data-massimale="{{ $massimale }}"
                data-max-balance="{{ $maxBalance ?? '' }}"
                data-saldo-disponibile="{{ $saldoDisponibile }}"
                data-email="{{ strtolower($ownerEmail) }}">

                {{-- Conto --}}
                <td style="padding-left:18px;">
                    <div style="font-weight:600;font-size:13px;color:var(--ink);line-height:1.3;">{{ $account->display_name }}</div>
                    <code style="font-size:10px;color:var(--ink-muted);background:var(--surface-soft);border:1px solid var(--line);border-radius:4px;padding:1px 6px;letter-spacing:.04em;display:inline-block;margin-top:3px;">{{ $account->account_number }}</code>
                </td>

                {{-- Email + data --}}
                <td>
                    <div style="font-size:12px;color:var(--ink);font-family:monospace;letter-spacing:.01em;">{{ $ownerEmail }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">
                        aperto {{ $account->created_at?->format('d/m/Y') ?? '—' }}
                    </div>
                </td>

                {{-- Profilo --}}
                <td>
                    <span class="chip {{ $account->owner_type === 'company' ? '' : 'success' }}" style="font-size:11px;">
                        {{ $account->owner_type === 'company' ? 'Azienda' : ($account->owner_type === 'private' ? 'Privato' : 'Sistema') }}
                    </span>
                </td>

                {{-- Stato --}}
                <td>
                    <span class="chip {{ $account->status === 'active' ? 'success' : 'pink' }}" style="font-size:11px;">
                        {{ $account->status === 'active' ? 'Attivo' : 'Sospeso' }}
                    </span>
                    @if($account->managedUsers->isNotEmpty())
                        <div style="margin-top:4px;font-size:10px;color:var(--ink-muted);">
                            @foreach($account->managedUsers->take(2) as $u)
                                {{ $u->name }}{{ !$loop->last ? ', ' : '' }}
                            @endforeach
                            @if($account->managedUsers->count() > 2)
                                +{{ $account->managedUsers->count() - 2 }} altri
                            @endif
                        </div>
                    @endif
                </td>

                {{-- Saldo --}}
                <td style="text-align:right;">
                    <strong style="font-size:14px;font-variant-numeric:tabular-nums;color:{{ $balance < 0 ? 'var(--danger)' : ($balance > 0 ? 'var(--teal)' : 'var(--ink-muted)') }};">
                        {{ ky_format($balance) }} <span style="font-size:11px;font-weight:400;opacity:.7;">{{ $account->currency_code }}</span>
                    </strong>
                </td>

                {{-- Disponibile (+ fido inline) --}}
                <td style="text-align:right;">
                    <span style="font-size:13px;font-variant-numeric:tabular-nums;color:var(--ink);">
                        {{ ky_format($saldoDisponibile) }} <span style="font-size:11px;opacity:.55;">{{ $account->currency_code }}</span>
                    </span>
                    @if($massimale > 0)
                        <div style="font-size:10px;color:var(--accent);margin-top:2px;">
                            +{{ ky_format($massimale) }} KY fido
                        </div>
                    @endif
                </td>

                {{-- Stato commerciale --}}
                @php
                    $badge      = $account->commercialStatusBadge();
                    $badgeShort = match($badge['color']) {
                        'green'  => ['label' => 'Libera vendita',   'title' => $badge['label']],
                        'yellow' => ['label' => 'In debito',        'title' => $badge['label']],
                        default  => ['label' => 'Tetto raggiunto',  'title' => $badge['label']],
                    };
                @endphp
                <td>
                    <span class="chip {{ $badge['color'] === 'green' ? 'success' : ($badge['color'] === 'yellow' ? 'ac-warn' : 'pink') }}"
                          style="font-size:11px;white-space:nowrap;"
                          title="{{ $badgeShort['title'] }}">
                        {{ $badgeShort['label'] }}
                    </span>
                </td>

                {{-- Azioni --}}
                <td class="ac-sticky-col">
                    <a class="cta secondary" href="{{ route('admin.accounts.show', $account) }}"
                       style="padding:5px 12px;font-size:11px;white-space:nowrap;">Dettagli</a>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div id="ac-empty" style="display:none;padding:48px;text-align:center;">
        <div style="font-size:32px;margin-bottom:8px;opacity:.2;">&#9680;</div>
        <div style="font-size:14px;color:var(--ink-muted);">Nessun conto corrisponde ai filtri selezionati.</div>
    </div>
</section>

@push('scripts')
<style>
.ac-select {
    font-size: 12px;
    padding: 6px 28px 6px 10px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--surface-soft);
    color: var(--ink);
    cursor: pointer;
    outline: none;
    appearance: auto;
    transition: border-color .15s;
}
.ac-select:focus { border-color: var(--primary); }
.ac-th { cursor: pointer; user-select: none; }
.ac-th:hover { color: var(--primary); }
.ac-sort-icon { font-size: 10px; opacity: .4; margin-left: 2px; }
.ac-th.sorted .ac-sort-icon { opacity: 1; color: var(--primary); }
.ac-sticky-col {
    position: sticky;
    right: 0;
    background: var(--surface);
    box-shadow: -3px 0 8px rgba(10,30,60,.06);
    text-align: right;
    padding-right: 14px !important;
}
#ac-table tbody tr:hover .ac-sticky-col { background: var(--surface-soft); }
.chip.ac-warn { background: var(--warning-soft); color: var(--warning); border-color: rgba(120,53,15,.18); }
</style>
<script>
(function () {
    const rows    = Array.from(document.querySelectorAll('.ac-row'));
    const search  = document.getElementById('ac-search');
    const selStat    = document.getElementById('ac-filter-status');
    const selProfile = document.getElementById('ac-filter-profile');
    const selBal     = document.getElementById('ac-filter-balance');
    const countEl = document.getElementById('ac-count');
    const emptyEl = document.getElementById('ac-empty');
    let sortCol = null, sortDir = 1;

    function matchBalance(r, mode) {
        const bal  = parseFloat(r.dataset.balance  || 0);
        const mas  = parseFloat(r.dataset.massimale || 0);
        const maxB = r.dataset.maxBalance !== '' ? parseFloat(r.dataset.maxBalance) : null;
        const disp = parseFloat(r.dataset.saldoDisponibile || 0);

        switch (mode) {
            case 'positive':  return bal > 0;
            case 'negative':  return bal < 0;
            case 'zero':      return bal === 0;
            case 'with_fido': return mas > 0;
            // >80% del fido usato: saldo disponibile < 20% del massimale
            case 'near_min':  return mas > 0 && disp < mas * 0.2;
            // saldo > 80% del tetto massimo
            case 'near_max':  return maxB !== null && maxB > 0 && bal >= maxB * 0.8;
            // sopra il tetto
            case 'over_max':  return maxB !== null && maxB > 0 && bal >= maxB;
            default:          return true;
        }
    }

    function render() {
        const q       = search.value.toLowerCase().trim();
        const stat    = selStat.value;
        const profile = selProfile.value;
        const bal     = selBal.value;
        let visible = 0;

        rows.forEach(r => {
            const ok = (!q      || r.dataset.name.includes(q))
                    && (stat    === 'all' || r.dataset.status    === stat)
                    && (profile === 'all' || r.dataset.ownerType === profile)
                    && matchBalance(r, bal);
            r.style.display = ok ? '' : 'none';
            if (ok) visible++;
        });

        countEl.textContent = visible + (visible === 1 ? ' conto' : ' conti');
        emptyEl.style.display = visible === 0 ? '' : 'none';
    }

    [search, selStat, selProfile, selBal].forEach(el => el.addEventListener('input', render));

    // Ordinamento colonne
    document.querySelectorAll('.ac-th').forEach(th => {
        th.addEventListener('click', function () {
            const col = this.dataset.col;
            if (sortCol === col) sortDir *= -1; else { sortCol = col; sortDir = 1; }
            document.querySelectorAll('.ac-th').forEach(t => {
                t.classList.remove('sorted');
                t.querySelector('.ac-sort-icon').textContent = '↕';
            });
            this.classList.add('sorted');
            this.querySelector('.ac-sort-icon').textContent = sortDir === 1 ? '↑' : '↓';
            const tbody = document.querySelector('#ac-table tbody');
            Array.from(tbody.querySelectorAll('tr')).sort((a, b) => {
                if (col === 'balance') {
                    return (parseFloat(a.dataset.balance||0) - parseFloat(b.dataset.balance||0)) * sortDir;
                }
                const av = col === 'email' ? a.dataset.email : a.dataset.name;
                const bv = col === 'email' ? b.dataset.email : b.dataset.name;
                return (av||'').localeCompare(bv||'', 'it') * sortDir;
            }).forEach(r => tbody.appendChild(r));
        });
    });

    render();
})();
</script>
@endpush
@endsection
