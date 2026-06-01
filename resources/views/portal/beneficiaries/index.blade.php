@extends('layouts.portal')

@section('content')

<div style="max-width:820px; margin:0 auto; padding:8px 0 40px;">

    {{-- Flash --}}
    @if(session('portal_success'))
        <div style="background:var(--success-soft);border:1px solid #a7f3d0;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:14px;color:var(--success);">
            {{ session('portal_success') }}
        </div>
    @endif
    @if($errors->any())
        <div style="background:var(--danger-soft);border:1px solid #fecdd3;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:14px;color:var(--danger);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Header --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;gap:16px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin-bottom:4px;">Beneficiari salvati</h1>
            <p style="font-size:14px;color:var(--ink-muted);">Rubrica destinatari frequenti per velocizzare i pagamenti.</p>
        </div>
        <button onclick="document.getElementById('add-form').classList.toggle('hidden')"
                class="cta" style="flex-shrink:0;white-space:nowrap;">
            + Aggiungi beneficiario
        </button>
    </div>

    {{-- Form aggiunta (collassabile) --}}
    <div id="add-form" class="card card-pad hidden" style="margin-bottom:24px;">
        <h2 style="font-size:16px;font-weight:700;color:var(--ink);margin-bottom:18px;">Nuovo beneficiario</h2>
        <form method="POST" action="{{ route('portal.beneficiaries.store') }}">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">

                {{-- Cerca account --}}
                <div style="grid-column:1/-1;">
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">
                        Azienda / Conto destinatario <span style="color:var(--danger)">*</span>
                    </label>
                    <div style="position:relative;">
                        <input type="text" id="beneficiary-search"
                               placeholder="Cerca per nome azienda…"
                               autocomplete="off"
                               style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;outline:none;">
                        <input type="hidden" name="beneficiary_account_id" id="beneficiary-account-id">
                        <div id="search-results"
                             style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--surface);border:1px solid var(--line);border-radius:8px;box-shadow:var(--shadow-sm);z-index:50;max-height:240px;overflow-y:auto;">
                        </div>
                    </div>
                    <div id="selected-account" style="display:none;margin-top:8px;padding:8px 12px;background:var(--success-soft);border:1px solid #a7f3d0;border-radius:8px;font-size:13px;color:var(--success);font-weight:600;"></div>
                </div>

                <div>
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Alias (opzionale)</label>
                    <input type="text" name="alias" value="{{ old('alias') }}" placeholder="es. Fornitore preferito"
                           maxlength="100"
                           style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;outline:none;">
                </div>

                <div>
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Note (opzionale)</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" placeholder="es. Pagamento mensile canone"
                           maxlength="500"
                           style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;outline:none;">
                </div>
            </div>

            <div style="display:flex;gap:10px;">
                <button type="submit" class="cta">Salva beneficiario</button>
                <button type="button" onclick="document.getElementById('add-form').classList.add('hidden')"
                        class="cta secondary">Annulla</button>
            </div>
        </form>
    </div>

    {{-- Lista beneficiari --}}
    @if($beneficiaries->isEmpty())
        <div class="card card-pad" style="text-align:center;padding:48px 24px;">
            <div style="font-size:36px;margin-bottom:12px;">📋</div>
            <div style="font-size:16px;font-weight:700;color:var(--ink);margin-bottom:8px;">Nessun beneficiario salvato</div>
            <div style="font-size:14px;color:var(--ink-muted);margin-bottom:24px;">
                Salva le aziende con cui scambi più spesso per velocizzare i pagamenti.
            </div>
            <button onclick="document.getElementById('add-form').classList.remove('hidden');document.getElementById('add-form').scrollIntoView({behavior:'smooth'});"
                    class="cta">Aggiungi il primo beneficiario</button>
        </div>
    @else
        <div class="card" style="overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--surface-soft);border-bottom:1px solid var(--line);">
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;">Beneficiario</th>
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;">Alias</th>
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;">Note</th>
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;">Aggiunto</th>
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:right;">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($beneficiaries as $b)
                    @php
                        $bAccount = $b->beneficiaryAccount;
                        $companyName = $bAccount?->company?->name ?? $bAccount?->ownerUser?->name ?? 'N/D';
                    @endphp
                    <tr style="border-bottom:1px solid var(--line-soft);" id="row-{{ $b->id }}">

                        <td style="padding:12px 16px;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:38px;height:38px;border-radius:10px;background:var(--surface-soft);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">
                                    🏢
                                </div>
                                <div>
                                    <div style="font-size:14px;font-weight:700;color:var(--ink);">{{ $companyName }}</div>
                                    @if($bAccount?->company?->slug)
                                        <a href="{{ route('portal.companies.show', $bAccount->company->slug) }}"
                                           style="font-size:11px;color:var(--ink-muted);text-decoration:none;" target="_blank">
                                            Vedi profilo →
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </td>

                        <td style="padding:12px 16px;">
                            <span class="view-alias-{{ $b->id }}">
                                @if($b->alias)
                                    <span style="background:var(--surface-soft);border:1px solid var(--line);border-radius:6px;padding:3px 8px;font-size:12px;font-weight:600;color:var(--ink);">
                                        {{ $b->alias }}
                                    </span>
                                @else
                                    <span style="font-size:12px;color:var(--ink-muted);">—</span>
                                @endif
                            </span>
                        </td>

                        <td style="padding:12px 16px;font-size:13px;color:var(--ink-muted);max-width:200px;">
                            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;">{{ $b->notes ?: '—' }}</span>
                        </td>

                        <td style="padding:12px 16px;font-size:12px;color:var(--ink-muted);white-space:nowrap;">
                            {{ $b->created_at->format('d/m/Y') }}
                        </td>

                        <td style="padding:12px 16px;text-align:right;white-space:nowrap;">
                            {{-- Paga subito --}}
                            <a href="{{ route('portal.pay.form') }}?to={{ $b->beneficiary_account_id }}"
                               class="cta" style="font-size:12px;padding:5px 10px;min-height:28px;">
                                💸 Paga
                            </a>

                            {{-- Edit inline --}}
                            <button onclick="toggleEdit({{ $b->id }})"
                                    class="cta secondary" style="font-size:12px;padding:5px 10px;min-height:28px;margin-left:4px;">
                                ✏️
                            </button>

                            {{-- Delete --}}
                            <form method="POST" action="{{ route('portal.beneficiaries.destroy', $b) }}"
                                  style="display:inline-block;margin-left:4px;"
                                  onsubmit="return confirm('Rimuovere {{ addslashes($companyName) }} dai beneficiari?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="cta secondary" style="font-size:12px;padding:5px 10px;min-height:28px;color:var(--danger);">
                                    🗑
                                </button>
                            </form>
                        </td>
                    </tr>

                    {{-- Inline edit row --}}
                    <tr id="edit-row-{{ $b->id }}" style="display:none;background:var(--surface-soft);border-bottom:1px solid var(--line);">
                        <td colspan="5" style="padding:14px 16px;">
                            <form method="POST" action="{{ route('portal.beneficiaries.update', $b) }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                                @csrf
                                @method('PATCH')
                                <div style="flex:1;min-width:180px;">
                                    <label style="font-size:12px;font-weight:600;color:var(--ink-muted);display:block;margin-bottom:4px;">Alias</label>
                                    <input type="text" name="alias" value="{{ $b->alias }}" placeholder="Alias opzionale" maxlength="100"
                                           style="width:100%;border:1px solid var(--line);border-radius:6px;padding:8px 10px;font-size:13px;background:var(--surface);color:var(--ink);box-sizing:border-box;">
                                </div>
                                <div style="flex:2;min-width:200px;">
                                    <label style="font-size:12px;font-weight:600;color:var(--ink-muted);display:block;margin-bottom:4px;">Note</label>
                                    <input type="text" name="notes" value="{{ $b->notes }}" placeholder="Note opzionali" maxlength="500"
                                           style="width:100%;border:1px solid var(--line);border-radius:6px;padding:8px 10px;font-size:13px;background:var(--surface);color:var(--ink);box-sizing:border-box;">
                                </div>
                                <div style="display:flex;gap:8px;">
                                    <button type="submit" class="cta" style="font-size:13px;padding:8px 14px;">Salva</button>
                                    <button type="button" onclick="toggleEdit({{ $b->id }})" class="cta secondary" style="font-size:13px;padding:8px 14px;">Annulla</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>

@push('scripts')
<script>
function toggleEdit(id) {
    const row = document.getElementById('edit-row-' + id);
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

// Autocomplete beneficiario
const searchInput = document.getElementById('beneficiary-search');
const resultsBox  = document.getElementById('search-results');
const hiddenInput = document.getElementById('beneficiary-account-id');
const selectedBox = document.getElementById('selected-account');

if (searchInput) {
    let debounce;
    searchInput.addEventListener('input', function () {
        clearTimeout(debounce);
        const q = this.value.trim();
        hiddenInput.value = '';
        selectedBox.style.display = 'none';

        if (q.length < 2) { resultsBox.style.display = 'none'; return; }

        debounce = setTimeout(() => {
            fetch('/beneficiari/cerca?q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                resultsBox.innerHTML = '';
                if (data.length === 0) {
                    resultsBox.innerHTML = '<div style="padding:12px 14px;font-size:13px;color:var(--ink-muted);">Nessun risultato</div>';
                } else {
                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--line-soft);';
                        div.innerHTML = '<strong>' + item.display_name + '</strong>' +
                            (item.company_name && item.alias ? ' <span style="color:var(--ink-muted);font-size:11px;">(' + item.alias + ')</span>' : '');
                        div.addEventListener('mouseenter', () => div.style.background = 'var(--surface-soft)');
                        div.addEventListener('mouseleave', () => div.style.background = '');
                        div.addEventListener('click', () => {
                            hiddenInput.value = item.id;
                            searchInput.value = item.display_name;
                            selectedBox.textContent = '✓ Selezionato: ' + item.display_name;
                            selectedBox.style.display = 'block';
                            resultsBox.style.display = 'none';
                        });
                        resultsBox.appendChild(div);
                    });
                }
                resultsBox.style.display = 'block';
            });
        }, 280);
    });

    document.addEventListener('click', (e) => {
        if (!resultsBox.contains(e.target) && e.target !== searchInput) {
            resultsBox.style.display = 'none';
        }
    });
}
</script>
@endpush

@endsection
