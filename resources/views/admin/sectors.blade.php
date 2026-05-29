@extends('layouts.portal')

@section('content')
<style>
    .sectors-grid {
        display: grid;
        grid-template-columns: minmax(0,1fr) 360px;
        gap: 24px;
        align-items: start;
    }
    @media(max-width:900px) { .sectors-grid { grid-template-columns: 1fr; } }

    .sector-table { width:100%; border-collapse:collapse; font-size:13.5px; }
    .sector-table th {
        text-align:left; padding:10px 14px;
        font-size:11px; font-weight:700; text-transform:uppercase;
        letter-spacing:.06em; color:var(--ink-muted);
        border-bottom:2px solid var(--line);
    }
    .sector-table td {
        padding:10px 14px;
        border-bottom:1px solid var(--line);
        vertical-align:middle;
    }
    .sector-table tr:last-child td { border-bottom:none; }
    .sector-table tr:hover td { background:var(--surface-soft); }

    .badge-active   { background:#d1fae5; color:#065f46; border-radius:4px; padding:2px 7px; font-size:11px; font-weight:700; }
    .badge-inactive { background:#f3f4f6; color:#6b7280; border-radius:4px; padding:2px 7px; font-size:11px; font-weight:700; }

    .inline-edit-form { display:flex; gap:8px; align-items:center; }
    .inline-edit-form input[type=text] { flex:1; min-width:0; font-size:13px; padding:5px 9px; }
    .inline-edit-form input[type=number] { width:64px; font-size:13px; padding:5px 9px; }
    .inline-edit-form .cta { font-size:12px; padding:5px 12px; min-height:0; }
</style>

@if(session('success'))
<div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="alert alert-error" style="margin-bottom:16px;">{{ session('error') }}</div>
@endif

<div class="sectors-grid">

    {{-- Lista settori --}}
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:18px 20px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <h2 style="margin:0;font-size:15px;font-weight:700;">Settori del circuito</h2>
            <span style="font-size:12px;color:var(--ink-muted);">{{ $sectors->count() }} settori</span>
        </div>
        <table class="sector-table">
            <thead>
                <tr>
                    <th>Ord.</th>
                    <th>Nome</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sectors as $sector)
                <tr>
                    <td style="color:var(--ink-muted);width:48px;">{{ $sector->sort_order }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.sectors.update', $sector) }}" class="inline-edit-form">
                            @csrf @method('PUT')
                            <input type="text" name="name" value="{{ $sector->name }}" required maxlength="120">
                            <input type="number" name="sort_order" value="{{ $sector->sort_order }}" min="0" title="Ordine">
                            <input type="hidden" name="is_active" value="{{ $sector->is_active ? 1 : 0 }}">
                            <button class="cta secondary" type="submit">Salva</button>
                        </form>
                    </td>
                    <td>
                        @if($sector->is_active)
                            <span class="badge-active">Attivo</span>
                        @else
                            <span class="badge-inactive">Disattivo</span>
                        @endif
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:nowrap;">
                            {{-- Toggle attivo/disattivo --}}
                            <form method="POST" action="{{ route('admin.sectors.toggle', $sector) }}">
                                @csrf
                                <button class="cta secondary" type="submit" style="font-size:11px;padding:4px 10px;min-height:0;"
                                        title="{{ $sector->is_active ? 'Disattiva' : 'Riattiva' }}">
                                    {{ $sector->is_active ? 'Disattiva' : 'Riattiva' }}
                                </button>
                            </form>
                            {{-- Elimina --}}
                            <form method="POST" action="{{ route('admin.sectors.destroy', $sector) }}"
                                  onsubmit="return confirm('Eliminare il settore «{{ $sector->name }}»?')">
                                @csrf @method('DELETE')
                                <button class="cta danger" type="submit" style="font-size:11px;padding:4px 10px;min-height:0;">
                                    Elimina
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun settore ancora.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Aggiungi nuovo settore --}}
    <div class="card" style="position:sticky;top:24px;">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:700;">Aggiungi settore</h3>
        <form method="POST" action="{{ route('admin.sectors.store') }}">
            @csrf
            <div class="field">
                <label for="new_name">Nome settore</label>
                <input type="text" id="new_name" name="name"
                       value="{{ old('name') }}"
                       placeholder="es. Benessere animali"
                       maxlength="120" required>
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field" style="margin-top:12px;">
                <label for="new_sort">Ordine <span style="font-weight:400;color:var(--ink-muted)">(0 = primo)</span></label>
                <input type="number" id="new_sort" name="sort_order"
                       value="{{ old('sort_order', 99) }}"
                       min="0" style="width:100px;">
            </div>
            <div class="form-actions" style="margin-top:16px;">
                <button class="cta" type="submit">Aggiungi</button>
            </div>
        </form>

        <hr style="margin:20px 0;border:none;border-top:1px solid var(--line);">
        <p style="font-size:12px;color:var(--ink-muted);margin:0;line-height:1.6;">
            I settori <strong>attivi</strong> appaiono nella selezione durante l'onboarding e nella pagina "Profilo azienda".<br>
            I settori <strong>disattivi</strong> non sono selezionabili ma vengono preservati per le aziende che li usano già.
        </p>
    </div>

</div>
@endsection
