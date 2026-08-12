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
    .badge-group    { background:#e0e7ff; color:#3730a3; border-radius:4px; padding:2px 7px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }

    .tree-prefix { color:var(--ink-muted); font-family:monospace; white-space:pre; user-select:none; }

    .inline-edit-form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .inline-edit-form input[type=text] { flex:1; min-width:120px; font-size:13px; padding:5px 9px; }
    .inline-edit-form input[type=number] { width:60px; font-size:13px; padding:5px 9px; }
    .inline-edit-form select { font-size:12.5px; padding:5px 8px; max-width:180px; }
    .inline-edit-form .cta { font-size:12px; padding:5px 12px; min-height:0; }
</style>

@if(session('success'))
<div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="alert alert-error" style="margin-bottom:16px;">{{ session('error') }}</div>
@endif

<div class="sectors-grid">

    {{-- Lista categorie/sotto-categorie --}}
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:18px 20px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <h2 style="margin:0;font-size:15px;font-weight:700;">Categorie prodotto shop</h2>
            <span style="font-size:12px;color:var(--ink-muted);">{{ $categories->count() }} voci</span>
        </div>
        <table class="sector-table">
            <thead>
                <tr>
                    <th>Ord.</th>
                    <th>Nome / gerarchia</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $category)
                <tr>
                    <td style="color:var(--ink-muted);width:48px;">{{ $category->sort_order }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.listing-categories.update', $category) }}" class="inline-edit-form">
                            @csrf @method('PUT')
                            @if($category->depth > 0)
                                <span class="tree-prefix">{{ str_repeat('   ', $category->depth - 1) }}└─</span>
                            @endif
                            <input type="text" name="name" value="{{ $category->name }}" required maxlength="120">
                            <input type="number" name="sort_order" value="{{ $category->sort_order }}" min="0" title="Ordine">
                            {{-- Struttura fissa a 2 livelli: solo le categorie di primo livello sono
                                 selezionabili come "padre" — non si può creare una sotto-sotto-categoria. --}}
                            @if($category->depth === 0)
                                <select name="parent_id" title="Rendi sotto-categoria di...">
                                    <option value="">— Categoria principale —</option>
                                    @foreach($parents as $p)
                                        @if($p->id !== $category->id)
                                            <option value="{{ $p->id }}" @selected($category->parent_id === $p->id)>{{ $p->name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            @else
                                <select name="parent_id" title="Categoria principale">
                                    @foreach($parents as $p)
                                        <option value="{{ $p->id }}" @selected($category->parent_id === $p->id)>{{ $p->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <input type="hidden" name="is_active" value="{{ $category->is_active ? 1 : 0 }}">
                            <button class="cta secondary" type="submit">Salva</button>
                        </form>
                    </td>
                    <td>
                        @if(! $category->is_leaf)
                            <span class="badge-group" title="Ha sotto-categorie">Categoria</span>
                        @elseif($category->is_active)
                            <span class="badge-active">Attiva</span>
                        @else
                            <span class="badge-inactive">Disattiva</span>
                        @endif
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:nowrap;">
                            <form method="POST" action="{{ route('admin.listing-categories.toggle', $category) }}">
                                @csrf
                                <button class="cta secondary" type="submit" style="font-size:11px;padding:4px 10px;min-height:0;"
                                        title="{{ $category->is_active ? 'Disattiva' : 'Riattiva' }}">
                                    {{ $category->is_active ? 'Disattiva' : 'Riattiva' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.listing-categories.destroy', $category) }}"
                                  onsubmit="return confirm('Eliminare «{{ $category->name }}»?')">
                                @csrf @method('DELETE')
                                <button class="cta danger" type="submit" style="font-size:11px;padding:4px 10px;min-height:0;">
                                    Elimina
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessuna categoria ancora.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Aggiungi nuova categoria / sotto-categoria --}}
    <div class="card" style="position:sticky;top:24px;">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:700;">Aggiungi categoria</h3>
        <form method="POST" action="{{ route('admin.listing-categories.store') }}">
            @csrf
            <div class="field">
                <label for="new_name">Nome</label>
                <input type="text" id="new_name" name="name"
                       value="{{ old('name') }}"
                       placeholder="es. Benessere animali"
                       maxlength="120" required>
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field" style="margin-top:12px;">
                <label for="new_parent">Categoria principale <span style="font-weight:400;color:var(--ink-muted)">(opzionale)</span></label>
                <select id="new_parent" name="parent_id" style="width:100%;">
                    <option value="">— Nessuna (nuova categoria principale) —</option>
                    @foreach($parents as $p)
                        <option value="{{ $p->id }}" @selected((string) old('parent_id') === (string) $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
                @error('parent_id')<span class="field-error">{{ $message }}</span>@enderror
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
            Struttura a <strong>2 livelli</strong>: <strong>categorie</strong> principali e, dentro ciascuna,
            <strong>sotto-categorie</strong> facoltative (non è possibile creare sotto-categorie di sotto-categorie).<br>
            La categoria è sempre <strong>obbligatoria</strong> quando si pubblica un prodotto; la sotto-categoria resta <strong>facoltativa</strong>.<br>
            Rinominare una categoria da qui <strong>non scollega</strong> i prodotti già pubblicati con quella categoria.<br>
            Le categorie <strong>disattive</strong> non sono più selezionabili nei nuovi prodotti, ma restano assegnate a quelli che le usano già.
        </p>
    </div>

</div>
@endsection
