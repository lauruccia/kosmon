@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('portal.pagamenti-hub') }}">← Tutti i metodi</a>
<a class="cta secondary" href="{{ route('portal.companies') }}">Rubrica</a>
@endsection

@section('content')
<style>
.recent-chips { display:flex; flex-wrap:wrap; gap:7px; margin-bottom:14px; }
.recent-chip-btn {
    display:inline-flex; align-items:center; gap:6px;
    border:1.5px solid var(--line, #e5e7eb); border-radius:99px;
    padding:5px 12px 5px 7px; font-size:12.5px; font-weight:600;
    background:var(--surface,#fff); color:var(--ink,#111827);
    cursor:pointer; transition:border-color .15s, background .15s;
}
.recent-chip-btn:hover { border-color:var(--primary,#0f52c4); background:#f0f6ff; color:var(--primary); }
.recent-chip-btn.active { border-color:var(--primary,#0f52c4); background:#dbeafe; color:#1d4ed8; }
.chip-avatar {
    width:22px; height:22px; border-radius:50%;
    background:linear-gradient(135deg,#0f52c4,#6366f1);
    color:#fff; font-size:9px; font-weight:800;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
/* Live-search dropdown */
.search-wrapper { position:relative; }
.search-dropdown {
    position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:200;
    background:#fff; border:1.5px solid var(--primary,#0f52c4); border-radius:10px;
    box-shadow:0 8px 32px rgba(0,0,0,.12); max-height:240px; overflow-y:auto;
    display:none;
}
.search-dropdown.open { display:block; }
.search-option {
    display:flex; align-items:center; gap:10px; padding:10px 14px;
    cursor:pointer; transition:background .1s; font-size:13px;
}
.search-option:hover { background:#f0f6ff; }
.search-option strong { font-weight:700; color:var(--ink); }
.search-option small { color:var(--ink-muted,#6b7280); font-size:11.5px; }
.search-no-results { padding:14px; text-align:center; color:var(--ink-muted); font-size:13px; }
</style>

    <div class="summary-grid">
        <section class="card account-hero card-pad">
            <span class="k-tag">Conto di addebito</span>
            <h1 style="position:relative;z-index:1;margin:16px 0 18px;">{{ $currentAccount->display_name }}</h1>
            <div class="metric">
                <div class="metric-label">Circuito</div>
                <div class="metric-value">{{ $currentAccount->currency_code }}</div>
            </div>
            <div class="metric">
                <div class="metric-label">Saldo spendibile</div>
                <div class="metric-value">{{ ky_format($currentAccount->available_balance + max(0, optional($currentAccount->creditLimit)->massimale ?? 0)) }} KY</div>
            </div>
            <div class="stat-note">La causale viene registrata nel ledger del conto mittente e destinatario.</div>
        </section>

        <section class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Disposizione</span>
                    <h3 class="section-title">Nuovo pagamento</h3>
                </div>
                <span class="pill">KY transfer</span>
            </div>
            <div class="form-body">

                {{-- Chip destinatari recenti --}}
                @if($recentRecipients->isNotEmpty())
                <div style="margin-bottom:16px;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:8px;">Inviato di recente</div>
                    <div class="recent-chips" id="recentChips">
                        @foreach($recentRecipients as $rec)
                        <button type="button" class="recent-chip-btn" data-id="{{ $rec->id }}" data-name="{{ $rec->display_name }}">
                            <span class="chip-avatar">{{ mb_strtoupper(mb_substr($rec->display_name, 0, 1)) }}</span>
                            {{ Str::limit($rec->display_name, 20) }}
                        </button>
                        @endforeach
                    </div>
                </div>
                @endif

                <form method="post" action="{{ route('portal.pay.submit') }}" id="payForm">
                    @csrf
                    <div class="field-grid">
                        <div class="field">
                            <label for="account_search">Destinatario del pagamento</label>

                            {{-- Hidden select (per il submit) --}}
                            <select id="to_account_id" name="to_account_id" required style="display:none;">
                                <option value="">—</option>
                                @foreach ($counterpartyAccounts as $account)
                                    <option value="{{ $account->id }}"
                                        @selected(old('to_account_id', $preselectedToId) == $account->id)
                                        data-name="{{ $account->display_name }}">
                                        {{ $account->display_name }}
                                    </option>
                                @endforeach
                            </select>

                            {{-- Input visibile con live-search --}}
                            <div class="search-wrapper">
                                <input id="account_search" type="text" autocomplete="off"
                                    placeholder="Cerca per nome, numero conto…"
                                    style="width:100%;"
                                    value="{{ old('to_account_id', $preselectedToId) ? optional($counterpartyAccounts->firstWhere('id', old('to_account_id', $preselectedToId)))->display_name : '' }}">
                                <div class="search-dropdown" id="searchDropdown"></div>
                            </div>
                            <div id="selectedInfo" style="font-size:12px;color:var(--ink-muted);margin-top:4px;display:none;"></div>
                        </div>

                        <div class="field">
                            <label for="amount">Importo in KY</label>
                            <input id="amount" name="amount" type="number" min="0.01" step="0.01"
                                value="{{ old('amount') }}" placeholder="Es. 15,00" required>
                            {{-- Info limiti inline --}}
                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                                @if($payRemainingToday !== null)
                                    @php
                                        $pctUsed = $payLimitDaily > 0 ? min(100, round($paySpentToday / $payLimitDaily * 100)) : 0;
                                        $chipColor = $pctUsed >= 90 ? '#fee2e2' : ($pctUsed >= 70 ? '#fef3c7' : '#dbeafe');
                                        $chipText  = $pctUsed >= 90 ? '#991b1b' : ($pctUsed >= 70 ? '#92400e' : '#1d4ed8');
                                    @endphp
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;background:{{ $chipColor }};color:{{ $chipText }};font-size:11px;font-weight:600;">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        Residuo oggi: {{ ky_format($payRemainingToday) }} KY
                                    </span>
                                @endif
                                @if($payLimitSingleTx !== null)
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;background:#f3f4f6;color:#374151;font-size:11px;font-weight:600;">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                                        Max per operazione: {{ ky_format($payLimitSingleTx) }} KY
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="field">
                            <label for="description">Causale</label>
                            <textarea id="description" name="description"
                                placeholder="Inserisci riferimento fattura o descrizione breve">{{ old('description') }}</textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <a href="{{ route('portal.pagamenti-hub') }}" class="cta secondary">Annulla</a>
                        <button type="submit" class="cta">Prosegui →</button>
                    </div>
                </form>
            </div>
        </section>
    </div>

@push('scripts')
<script>
(function() {
    // Dati account disponibili (iniezione PHP → JS)
    const accounts = @json($counterpartyAccounts->map(fn($a) => ['id' => $a->id, 'name' => $a->display_name, 'number' => $a->ky_account_number ?? '']));

    const searchInput    = document.getElementById('account_search');
    const hiddenSelect   = document.getElementById('to_account_id');
    const dropdown       = document.getElementById('searchDropdown');
    const selectedInfo   = document.getElementById('selectedInfo');
    const recentChips    = document.querySelectorAll('.recent-chip-btn');

    // Preseleziona dalla URL (?to=ID) o da old()
    const preselected = parseInt('{{ $preselectedToId }}');
    if (preselected) {
        const found = accounts.find(a => a.id === preselected);
        if (found) selectAccount(found.id, found.name, found.number);
    }

    function selectAccount(id, name, number) {
        hiddenSelect.value = id;
        searchInput.value  = name;
        showSelectedInfo(name, number);
        closeDropdown();
        // Evidenzia chip corrispondente
        recentChips.forEach(c => {
            c.classList.toggle('active', parseInt(c.dataset.id) === id);
        });
    }

    function showSelectedInfo(name, number) {
        if (number) {
            selectedInfo.style.display = 'block';
            selectedInfo.textContent = '✓ ' + name + (number ? ' · ' + number : '');
        }
    }

    function openDropdown(items) {
        if (!items.length) {
            dropdown.innerHTML = '<div class="search-no-results">Nessun risultato per "' + searchInput.value + '"</div>';
        } else {
            dropdown.innerHTML = items.map(a =>
                `<div class="search-option" data-id="${a.id}" data-name="${a.name}" data-number="${a.number}">
                    <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#0f52c4,#6366f1);display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:800;flex-shrink:0;">${a.name.charAt(0).toUpperCase()}</div>
                    <div><strong>${a.name}</strong><br><small>${a.number || ''}</small></div>
                </div>`
            ).join('');
            dropdown.querySelectorAll('.search-option').forEach(el => {
                el.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    selectAccount(parseInt(this.dataset.id), this.dataset.name, this.dataset.number);
                });
            });
        }
        dropdown.classList.add('open');
    }

    function closeDropdown() { dropdown.classList.remove('open'); }

    searchInput.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        hiddenSelect.value = '';
        selectedInfo.style.display = 'none';
        recentChips.forEach(c => c.classList.remove('active'));
        if (!q) { closeDropdown(); return; }
        const filtered = accounts.filter(a =>
            a.name.toLowerCase().includes(q) ||
            (a.number && a.number.toLowerCase().includes(q))
        ).slice(0, 8);
        openDropdown(filtered);
    });

    searchInput.addEventListener('focus', function() {
        if (!hiddenSelect.value && this.value.trim()) {
            this.dispatchEvent(new Event('input'));
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-wrapper')) closeDropdown();
    });

    // Chip recenti
    recentChips.forEach(chip => {
        chip.addEventListener('click', function() {
            const id   = parseInt(this.dataset.id);
            const name = this.dataset.name;
            const acc  = accounts.find(a => a.id === id);
            selectAccount(id, name, acc ? acc.number : '');
        });
    });

    // Validazione: deve essere selezionato un conto valido
    document.getElementById('payForm').addEventListener('submit', function(e) {
        if (!hiddenSelect.value) {
            e.preventDefault();
            searchInput.focus();
            searchInput.style.borderColor = '#dc2626';
            setTimeout(() => searchInput.style.borderColor = '', 2000);
            alert('Seleziona un destinatario valido dalla lista.');
        }
    });
})();
</script>
@endpush

@endsection
