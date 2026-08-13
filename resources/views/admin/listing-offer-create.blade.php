@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('admin.listing-offers.index') }}">← Torna alle offerte</a>
@endsection

@section('content')
<div style="max-width:640px;margin:0 auto;">

    <div class="page-intro">
        <span class="eyebrow">Shop del circuito</span>
        <h2>Nuova offerta della settimana</h2>
        <p>Scegli un prodotto già pubblicato (dall'azienda o da admin per suo conto) e mettilo in offerta con un prezzo scontato, una percentuale Kmoney dedicata e una scadenza. Se il prodotto ha già un'offerta attiva, questa la sostituirà.</p>
    </div>

    <section class="card light-card">
        <form method="POST" action="{{ route('admin.listing-offers.store') }}">
            @csrf

            <div class="field-grid">

                <div class="field">
                    <label>Prodotto *</label>
                    <select name="listing_id" id="offer-listing-select" required onchange="updateOfferFullPrice()">
                        <option value="">— Cerca o seleziona prodotto —</option>
                        @foreach($listings as $listing)
                            <option value="{{ $listing->id }}" data-price="{{ $listing->price_ky }}" @selected((int) old('listing_id') === $listing->id)>
                                {{ $listing->title }} — {{ $listing->company->name ?? '—' }} ({{ ky_format($listing->price_ky) }} KY)
                            </option>
                        @endforeach
                    </select>
                    <p style="margin:6px 0 0;font-size:11.5px;color:var(--ink-muted);line-height:1.4;">
                        Solo prodotti attualmente attivi nello shop. Digita per cercare tra titolo e azienda.
                    </p>
                </div>

                <div class="field">
                    <label>Prezzo in offerta (KY) *</label>
                    <input type="number" name="offer_price_ky" min="0.01" max="99999.99" step="0.01" value="{{ old('offer_price_ky') }}" required placeholder="es. 8.00">
                    <p id="offer-full-price-hint" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-muted);">Deve essere inferiore al prezzo pieno del prodotto selezionato.</p>
                </div>

                <div class="field">
                    <label>Percentuale Kmoney dell'offerta *</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        {{-- Ordine invertito (100% -> giù), stessa convenzione di admin/listing-create.blade.php. --}}
                        @foreach(array_reverse(\App\Models\Listing::KY_PERCENTAGES) as $pct)
                            @php
                                $eur = 100 - $pct;
                                $pctLabel = $pct === 100 ? '100% KY' : "{$pct}% KY + {$eur}% EUR";
                                $checked = (int) old('offer_ky_percentage', 100) === $pct;
                            @endphp
                            <label style="cursor:pointer;">
                                <input type="radio" name="offer_ky_percentage" value="{{ $pct }}" style="display:none;" class="offer-ky-pct-radio" {{ $checked ? 'checked' : '' }}
                                    onchange="document.querySelectorAll('.offer-ky-pct-btn').forEach(function(b){b.classList.remove('admin-ky-pct-btn--active');}); this.nextElementSibling.classList.add('admin-ky-pct-btn--active');">
                                <span class="admin-ky-pct-btn offer-ky-pct-btn {{ $checked ? 'admin-ky-pct-btn--active' : '' }}">{{ $pctLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p style="margin:6px 0 0;font-size:11.5px;color:var(--ink-muted);">In genere 100% KY. Se l'azienda del prodotto è in debito, verrà comunque forzata al 100% KY al salvataggio.</p>
                </div>

                <div class="field">
                    <label>Scadenza offerta *</label>
                    <input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}" required>
                    <p style="margin:6px 0 0;font-size:11.5px;color:var(--ink-muted);">Passata questa data, il prodotto torna automaticamente al prezzo pieno e sparisce dalla pagina "Offerte della settimana" — nessuna azione manuale necessaria.</p>
                </div>

            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:22px;">
                <a href="{{ route('admin.listing-offers.index') }}" class="cta secondary">Annulla</a>
                <button type="submit" class="cta">Crea offerta</button>
            </div>

        </form>
    </section>
</div>

<style>
    .admin-ky-pct-btn {
        display: inline-block; padding: 6px 14px; border-radius: 999px;
        font-size: 12px; font-weight: 700; border: 1.5px solid var(--line-strong);
        color: var(--ink-soft); background: var(--surface); transition: all .16s;
    }
    .admin-ky-pct-btn--active {
        background: var(--primary); color: #fff; border-color: var(--primary);
    }
</style>

<script>
    function updateOfferFullPrice() {
        var select = document.getElementById('offer-listing-select');
        var hint = document.getElementById('offer-full-price-hint');
        var opt = select.options[select.selectedIndex];
        var price = opt ? opt.getAttribute('data-price') : null;
        if (price) {
            hint.textContent = 'Deve essere inferiore al prezzo pieno del prodotto selezionato (' + (price / 100).toFixed(2).replace('.', ',') + ' KY).';
        } else {
            hint.textContent = 'Deve essere inferiore al prezzo pieno del prodotto selezionato.';
        }
    }
    document.addEventListener('DOMContentLoaded', updateOfferFullPrice);
</script>
@endsection
