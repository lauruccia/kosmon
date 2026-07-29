@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('admin.listings.index') }}">← Torna alla moderazione</a>
@endsection

@section('content')
<div style="max-width:760px;margin:0 auto;">

    <div class="page-intro">
        <span class="eyebrow">Moderazione shop</span>
        <h2>Nuovo prodotto per conto di un'azienda</h2>
        <p>Pubblica un prodotto assegnandolo a un'azienda del circuito: comparirà nello shop esattamente come se l'avesse pubblicato lei stessa.</p>
    </div>

    <section class="card light-card">
        <form method="POST" action="{{ route('admin.listings.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="field-grid">

                <div class="field">
                    <label>Azienda *</label>
                    <select name="company_id" required>
                        <option value="">— Seleziona azienda —</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}" @selected((int) old('company_id') === $company->id)>{{ $company->name }}</option>
                        @endforeach
                    </select>
                    <p style="margin:6px 0 0;font-size:11.5px;color:var(--ink-muted);line-height:1.4;">
                        Il prodotto verrà assegnato a questa azienda. Se il suo saldo è negativo o al tetto massimo,
                        valgono le stesse regole KY/EUR che si applicherebbero se lo pubblicasse lei: il salvataggio
                        darà errore in caso di percentuale non consentita.
                    </p>
                </div>

                <div class="field">
                    <label>Titolo prodotto / servizio *</label>
                    <input type="text" name="title" value="{{ old('title') }}" required maxlength="160" placeholder="es. Consulenza SEO mensile">
                </div>

                <div class="field">
                    <label>Descrizione *</label>
                    <textarea name="description" required maxlength="2000" rows="5" placeholder="Descrivi il prodotto/servizio in dettaglio: cosa include, modalità di erogazione, eventuali prerequisiti…">{{ old('description') }}</textarea>
                    <p style="margin:6px 0 0;font-size:11.5px;color:var(--ink-muted);">Massimo 2000 caratteri</p>
                </div>

                <div class="field-inline">
                    <div class="field">
                        <label>Categoria *</label>
                        <select name="category" required>
                            @foreach($categories as $slug => $label)
                                <option value="{{ $slug }}" @selected(old('category') === $slug)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label>Prezzo totale (KY) *</label>
                        {{-- price_ky è in centesimi: qui si digita il valore KY (es. "10.50"),
                             convertito in centesimi da ListingController::validateListing(). --}}
                        <input type="number" name="price_ky" min="0.01" max="99999.99" step="0.01" value="{{ old('price_ky') }}" required placeholder="es. 10.00">
                    </div>
                </div>

                <div class="field">
                    <label>Mix pagamento KY/EUR *</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        @foreach(\App\Models\Listing::KY_PERCENTAGES as $pct)
                            @php
                                $eur = 100 - $pct;
                                $pctLabel = $pct === 100 ? '100% KY' : ($pct === 0 ? '100% EUR' : "{$pct}% KY + {$eur}% EUR");
                                $checked = (int) old('ky_percentage', 100) === $pct;
                            @endphp
                            <label style="cursor:pointer;">
                                <input type="radio" name="ky_percentage" value="{{ $pct }}" style="display:none;" class="admin-ky-pct-radio" {{ $checked ? 'checked' : '' }}
                                    onchange="document.querySelectorAll('.admin-ky-pct-btn').forEach(function(b){b.classList.remove('admin-ky-pct-btn--active');}); this.nextElementSibling.classList.add('admin-ky-pct-btn--active');">
                                <span class="admin-ky-pct-btn {{ $checked ? 'admin-ky-pct-btn--active' : '' }}">{{ $pctLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="field">
                    <label>Disponibilità *</label>
                    @php $stockMode = old('stock_mode', 'unlimited'); @endphp
                    <div style="display:flex;gap:16px;align-items:center;font-size:13px;margin-bottom:8px;">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:400;">
                            <input type="radio" name="stock_mode" value="unlimited" {{ $stockMode === 'unlimited' ? 'checked' : '' }}
                                onchange="document.getElementById('admin-stock-qty').style.display='none'">
                            Illimitata
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:400;">
                            <input type="radio" name="stock_mode" value="limited" {{ $stockMode === 'limited' ? 'checked' : '' }}
                                onchange="document.getElementById('admin-stock-qty').style.display='block'">
                            Scorta limitata
                        </label>
                    </div>
                    <div id="admin-stock-qty" style="{{ $stockMode === 'limited' ? '' : 'display:none;' }}max-width:180px;">
                        <input type="number" name="stock_quantity" min="0" max="999999" value="{{ old('stock_quantity') }}" placeholder="es. 20">
                    </div>
                </div>

                <div class="field">
                    <label>Tipo di consegna / erogazione *</label>
                    @php $deliveryType = old('delivery_type', \App\Models\Listing::DELIVERY_TYPE_SERVIZIO); @endphp
                    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;font-size:13px;">
                        @foreach(\App\Models\Listing::DELIVERY_TYPES as $typeValue => $typeLabel)
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:400;">
                            <input type="radio" name="delivery_type" value="{{ $typeValue }}" {{ $deliveryType === $typeValue ? 'checked' : '' }}
                                onchange="document.getElementById('admin-shipping-cost').style.display = this.value === 'spedizione' ? 'block' : 'none';">
                            {{ $typeLabel }}
                        </label>
                        @endforeach
                    </div>
                    <div id="admin-shipping-cost" style="margin-top:8px;max-width:220px;{{ $deliveryType === \App\Models\Listing::DELIVERY_TYPE_SPEDIZIONE ? '' : 'display:none;' }}">
                        <input type="number" name="shipping_cost" min="0" max="99999.99" step="0.01" value="{{ old('shipping_cost') }}" placeholder="Costo spedizione KY (opzionale)">
                    </div>
                </div>

                <div class="field-inline">
                    <div class="field">
                        <label>Nota consegna / erogazione</label>
                        <input type="text" name="delivery_note" maxlength="120" value="{{ old('delivery_note') }}" placeholder="es. Consegna in 48h">
                    </div>
                    <div class="field">
                        <label>Scadenza offerta</label>
                        <input type="date" name="expires_at" min="{{ now()->addDay()->format('Y-m-d') }}" value="{{ old('expires_at') }}">
                    </div>
                </div>

                <div class="field">
                    <label>Contatto diretto (email o telefono)</label>
                    <input type="text" name="contact_info" maxlength="200" value="{{ old('contact_info') }}" placeholder="es. commerciale@azienda.it o +39 320 ...">
                    <p style="margin:6px 0 0;font-size:11.5px;color:var(--ink-muted);">Visibile agli utenti del circuito interessati al prodotto</p>
                </div>

                <div class="field">
                    <label>Foto prodotto</label>
                    <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp">
                    <p style="margin:6px 0 0;font-size:11.5px;color:var(--ink-muted);">Massimo 6 immagini · JPG, PNG, WebP · max 3 MB ciascuna</p>
                </div>

            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:22px;">
                <a href="{{ route('admin.listings.index') }}" class="cta secondary">Annulla</a>
                <button type="submit" class="cta">Pubblica prodotto</button>
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
@endsection
