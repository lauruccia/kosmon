@extends('layouts.portal')

@section('content')
<style>
    .attr-grid { display:grid; grid-template-columns: minmax(0,1fr) 340px; gap:24px; align-items:start; }
    @media(max-width:900px) { .attr-grid { grid-template-columns: 1fr; } }

    .attr-card { border:1px solid var(--line); border-radius:12px; padding:16px 18px; margin-bottom:14px; }
    .attr-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }

    .valori-riga { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:7px 0; border-top:1px solid var(--line); }
    .valori-riga:first-of-type { border-top:none; }

    .badge-active   { background:#d1fae5; color:#065f46; border-radius:4px; padding:2px 7px; font-size:11px; font-weight:700; }
    .badge-inactive { background:#f3f4f6; color:#6b7280; border-radius:4px; padding:2px 7px; font-size:11px; font-weight:700; }
    .slug-tag { font-family:monospace; font-size:11.5px; color:var(--ink-muted); }

    .inline-form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .inline-form input[type=text]   { flex:1; min-width:110px; font-size:13px; padding:5px 9px; }
    .inline-form input[type=number] { width:64px; font-size:13px; padding:5px 9px; }
    .inline-form .cta { font-size:12px; padding:5px 12px; min-height:0; }
    .link-btn { background:none; border:none; font-size:12px; font-weight:600; cursor:pointer; padding:4px 2px; }
</style>

@if(session('success'))
<div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="alert alert-error" style="margin-bottom:16px;">{{ session('error') }}</div>
@endif

<div class="attr-grid">

    {{-- ── Gli attributi e i loro valori ──────────────────────────────────── --}}
    <section class="card light-card">
        <h2 style="font-size:18px;font-weight:700;margin:0 0 4px;">Attributi dei prodotti variabili</h2>
        <p class="subtle" style="margin:0 0 20px;font-size:13px;line-height:1.55;">
            Sono il vocabolario comune dello shop: i venditori scelgono fra questi valori,
            non ne inventano di propri. Così "Taglia" resta una cosa sola in tutto il catalogo
            invece di diventare "taglie", "TAGLIA" e "Misura" a seconda di chi pubblica.
        </p>

        @forelse($attributi as $attributo)
        <div class="attr-card">
            <div class="attr-head">
                <form method="POST" action="{{ route('admin.listing-attributes.update', $attributo) }}" class="inline-form" style="flex:1;">
                    @csrf
                    @method('PUT')
                    <input type="text" name="name" value="{{ $attributo->name }}" maxlength="60" required>
                    <input type="number" name="sort_order" value="{{ $attributo->sort_order }}" min="0" max="999" title="Ordine">
                    <button type="submit" class="cta">Salva</button>
                </form>

                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="slug-tag">{{ $attributo->slug }}</span>
                    <span class="{{ $attributo->is_active ? 'badge-active' : 'badge-inactive' }}">
                        {{ $attributo->is_active ? 'attivo' : 'spento' }}
                    </span>

                    <form method="POST" action="{{ route('admin.listing-attributes.toggle', $attributo) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="link-btn" style="color:#0c4a86;">
                            {{ $attributo->is_active ? 'Disattiva' : 'Riattiva' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.listing-attributes.destroy', $attributo) }}"
                          onsubmit="return confirm('Eliminare l\'attributo «{{ $attributo->name }}» e tutti i suoi valori?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="link-btn" style="color:#b91c1c;">Elimina</button>
                    </form>
                </div>
            </div>

            <div style="margin-top:14px;">
                @forelse($attributo->values as $valore)
                <div class="valori-riga">
                    <form method="POST" action="{{ route('admin.listing-attributes.values.update', $valore) }}" class="inline-form" style="flex:1;">
                        @csrf
                        @method('PUT')
                        <input type="text" name="value" value="{{ $valore->value }}" maxlength="60" required>
                        <input type="number" name="sort_order" value="{{ $valore->sort_order }}" min="0" max="999" title="Ordine">
                        <button type="submit" class="cta">Salva</button>
                    </form>

                    <span class="slug-tag">{{ $valore->slug }}</span>
                    <span class="{{ $valore->is_active ? 'badge-active' : 'badge-inactive' }}">
                        {{ $valore->is_active ? 'attivo' : 'spento' }}
                    </span>

                    <form method="POST" action="{{ route('admin.listing-attributes.values.toggle', $valore) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="link-btn" style="color:#0c4a86;">
                            {{ $valore->is_active ? 'Disattiva' : 'Riattiva' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.listing-attributes.values.destroy', $valore) }}"
                          onsubmit="return confirm('Eliminare il valore «{{ $valore->value }}»?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="link-btn" style="color:#b91c1c;">Elimina</button>
                    </form>
                </div>
                @empty
                <p class="subtle" style="font-size:12.5px;margin:0 0 10px;">
                    Nessun valore: finché non ne aggiungi almeno uno, i venditori non vedranno questo attributo.
                </p>
                @endforelse

                <form method="POST" action="{{ route('admin.listing-attributes.values.store', $attributo) }}"
                      class="inline-form" style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--line);">
                    @csrf
                    <input type="text" name="value" placeholder="Nuovo valore (es. XL)" maxlength="60" required>
                    <input type="number" name="sort_order" value="99" min="0" max="999" title="Ordine">
                    <button type="submit" class="cta">Aggiungi valore</button>
                </form>
            </div>
        </div>
        @empty
        <p class="subtle" style="margin:0;">
            Ancora nessun attributo. Comincia dal riquadro qui accanto: "Taglia" e "Colore" sono i due più usati.
        </p>
        @endforelse
    </section>

    {{-- ── Nuovo attributo + spiegazione ──────────────────────────────────── --}}
    <div class="stack">
        <section class="card light-card">
            <h3 style="font-size:16px;font-weight:700;margin:0 0 14px;">Nuovo attributo</h3>
            <form method="POST" action="{{ route('admin.listing-attributes.store') }}">
                @csrf
                <div class="form-field" style="margin-bottom:12px;">
                    <label class="field-label">Nome</label>
                    <input type="text" name="name" class="field-input" placeholder="Taglia" maxlength="60" required>
                </div>
                <div class="form-field" style="margin-bottom:16px;">
                    <label class="field-label">Ordine</label>
                    <input type="number" name="sort_order" class="field-input" value="99" min="0" max="999">
                </div>
                <button type="submit" class="cta" style="width:100%;text-align:center;">Aggiungi attributo</button>
            </form>
        </section>

        <section class="card light-card">
            <h3 style="font-size:15px;font-weight:700;margin:0 0 10px;">Come funziona</h3>
            <p class="subtle" style="font-size:12.5px;line-height:1.6;margin:0 0 10px;">
                Il venditore, sul suo prodotto, spunta i valori che gli servono — per esempio S, M e L —
                e il sistema gli propone le combinazioni. Per ognuna può mettere un prezzo e una giacenza.
            </p>
            <p class="subtle" style="font-size:12.5px;line-height:1.6;margin:0 0 10px;">
                <strong>Il campo "Ordine" decide come si vedono.</strong> Il numero accanto a ogni valore
                è l'ordine in cui le taglie compaiono a chi compra: S, M, L, XL non hanno né un ordine
                alfabetico né uno di prezzo, quindi lo decidi tu qui. Usa numeri distanziati —
                10, 20, 30, 40 — così un domani ci infili la XS con un 5 senza rinumerare niente.
                A parità di numero vale l'ordine di creazione. Il numero sull'attributo fa lo stesso
                fra attributi: taglia prima di colore.
            </p>
            <p class="subtle" style="font-size:12.5px;line-height:1.6;margin:0 0 10px;">
                <strong>Rinominare non rompe niente:</strong> ogni attributo ha un codice interno che
                non cambia mai. Se scrivi "Misura" al posto di "Taglia", i prodotti restano dove sono.
            </p>
            <p class="subtle" style="font-size:12.5px;line-height:1.6;margin:0;">
                <strong>Disattivare è meglio che eliminare:</strong> un attributo spento sparisce dai
                form dei venditori ma i prodotti che lo usano continuano a funzionare. Eliminarlo, quando
                è già in uso, il sistema non te lo lascia fare.
            </p>
        </section>
    </div>
</div>
@endsection
