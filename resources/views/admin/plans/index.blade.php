@extends('layouts.portal')

@section('content')
<style>
    .plans-grid { display:grid; gap:16px; }
    .plan-card {
        border:1px solid var(--line); border-radius:var(--radius); background:var(--surface);
        box-shadow:var(--shadow-xs); overflow:hidden;
    }
    .plan-card-head {
        display:flex; align-items:center; gap:12px; padding:16px 20px;
        border-bottom:1px solid var(--line); flex-wrap:wrap;
    }
    .plan-dot { width:12px; height:12px; border-radius:50%; flex-shrink:0; }
    .plan-card-head h3 { margin:0; font-size:15.5px; font-weight:800; }
    .plan-card-head .subtle { font-size:12px; }
    .plan-companies-count {
        margin-left:auto; font-size:11.5px; font-weight:700; color:var(--ink-muted);
        background:var(--surface-soft); border:1px solid var(--line); border-radius:999px; padding:3px 10px;
    }
    .plan-form-grid {
        display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px 16px; padding:16px 20px;
    }
    .plan-form-grid .field-full { grid-column:1 / -1; }
    .plan-form-grid label {
        display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
        color:var(--ink-muted); margin-bottom:5px;
    }
    .plan-form-grid input[type=text], .plan-form-grid input[type=number], .plan-form-grid select, .plan-form-grid textarea {
        width:100%; font-size:13.5px; padding:7px 10px;
    }
    .plan-checkbox-row { display:flex; align-items:center; gap:8px; padding-top:22px; }
    .plan-checkbox-row input { width:16px; height:16px; }
    .plan-checkbox-row label { margin:0; font-size:12.5px; font-weight:600; text-transform:none; letter-spacing:0; color:var(--ink); cursor:pointer; }
    .plan-card-footer {
        display:flex; gap:8px; justify-content:flex-end; padding:12px 20px;
        border-top:1px solid var(--line); background:var(--surface-soft);
    }
    .plan-badge-preview {
        display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px;
        font-size:11px; font-weight:800; color:#fff;
    }
    @media(max-width:900px){ .plan-form-grid { grid-template-columns:repeat(2, minmax(0,1fr)); } }
    @media(max-width:560px){ .plan-form-grid { grid-template-columns:1fr; } }
</style>

@if(session('success'))
<div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="alert alert-error" style="margin-bottom:16px;">{{ session('error') }}</div>
@endif

<p class="subtle" style="margin-bottom:18px;max-width:760px;line-height:1.6;">
    Definisci qui i piani che le aziende possono sottoscrivere nella directory. Per ogni piano puoi impostare il canone annuale,
    se puo' pubblicare prodotti nello shop, lo stile grafica della card in directory (ricca / compatta / minimale),
    l'ordine di visualizzazione e se e' pagabile anche direttamente in KY oltre che con Stripe/PayPal/bonifico.
    Le aziende vedono questi piani e possono fare upgrade pagando la differenza da <a href="{{ route('portal.plan.index') }}">Il mio piano</a>.
</p>

<div class="plans-grid">
    @foreach($plans as $plan)
    <div class="plan-card">
        <form method="POST" action="{{ route('admin.plans.update', $plan) }}">
            @csrf @method('PUT')
            <div class="plan-card-head">
                <span class="plan-dot" style="background:{{ $plan->effective_badge_color }};"></span>
                <h3>{{ $plan->name }}</h3>
                <span class="subtle">/{{ $plan->slug }}</span>
                @if(! $plan->is_active)<span class="badge-inactive">Disattivo</span>@endif
                <span class="plan-companies-count">{{ $plan->companies_count }} {{ $plan->companies_count === 1 ? 'azienda' : 'aziende' }}</span>
            </div>
            <div class="plan-form-grid">
                <div>
                    <label>Nome</label>
                    <input type="text" name="name" value="{{ $plan->name }}" required maxlength="80">
                </div>
                <div>
                    <label>Canone annuale (EUR/KY)</label>
                    <input type="number" name="price_eur" value="{{ ky_input($plan->price_cents) }}" min="0" step="0.01" required>
                </div>
                <div>
                    <label>Stile card directory</label>
                    <select name="card_style" required>
                        @foreach(\App\Models\Plan::CARD_STYLES as $key => $label)
                            <option value="{{ $key }}" @selected($plan->card_style === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Ordine visualizzazione</label>
                    <input type="number" name="display_order" value="{{ $plan->display_order }}" min="0" required title="Piu' basso = mostrato prima nella directory">
                </div>
                <div class="field-full">
                    <label>Descrizione (mostrata alle aziende nella pagina piani)</label>
                    <textarea name="description" rows="2" maxlength="2000">{{ $plan->description }}</textarea>
                </div>
                <div>
                    <label>Colore badge</label>
                    <input type="text" name="badge_color" value="{{ $plan->badge_color }}" placeholder="#1d4ed8" pattern="^#[0-9a-fA-F]{{ '{6}' }}$">
                </div>
                <div class="plan-checkbox-row">
                    <input type="hidden" name="can_sell_products" value="0">
                    <input type="checkbox" id="sell-{{ $plan->id }}" name="can_sell_products" value="1" @checked($plan->can_sell_products)>
                    <label for="sell-{{ $plan->id }}">Puo' vendere prodotti nello shop</label>
                </div>
                <div class="plan-checkbox-row">
                    <input type="hidden" name="allow_ky_payment" value="0">
                    <input type="checkbox" id="ky-{{ $plan->id }}" name="allow_ky_payment" value="1" @checked($plan->allow_ky_payment)>
                    <label for="ky-{{ $plan->id }}">Pagabile anche in KY</label>
                </div>
                <div class="plan-checkbox-row">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="active-{{ $plan->id }}" name="is_active" value="1" @checked($plan->is_active)>
                    <label for="active-{{ $plan->id }}">Attivo (proponibile per nuove sottoscrizioni)</label>
                </div>
            </div>
            <div class="plan-card-footer">
                <button type="submit" class="cta secondary">Salva modifiche</button>
            </div>
        </form>
        <div class="plan-card-footer" style="border-top:none;">
            <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}"
                  onsubmit="return confirm('Eliminare definitivamente il piano «{{ $plan->name }}»?')">
                @csrf @method('DELETE')
                <button type="submit" class="cta danger" style="font-size:12px;padding:6px 12px;min-height:0;">Elimina</button>
            </form>
        </div>
    </div>
    @endforeach
</div>

<div class="card" style="margin-top:20px;">
    <h3 style="margin:0 0 14px;font-size:14.5px;font-weight:700;">Aggiungi nuovo piano</h3>
    <form method="POST" action="{{ route('admin.plans.store') }}">
        @csrf
        <div class="plan-form-grid" style="padding:0;">
            <div>
                <label>Nome</label>
                <input type="text" name="name" value="{{ old('name') }}" placeholder="es. Premium Plus" required maxlength="80">
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div>
                <label>Canone annuale (EUR/KY)</label>
                <input type="number" name="price_eur" value="{{ old('price_eur', 0) }}" min="0" step="0.01" required>
                @error('price_eur')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div>
                <label>Stile card directory</label>
                <select name="card_style" required>
                    @foreach(\App\Models\Plan::CARD_STYLES as $key => $label)
                        <option value="{{ $key }}" @selected(old('card_style') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Ordine visualizzazione</label>
                <input type="number" name="display_order" value="{{ old('display_order', 50) }}" min="0" required>
            </div>
            <div class="field-full">
                <label>Descrizione</label>
                <textarea name="description" rows="2" maxlength="2000">{{ old('description') }}</textarea>
            </div>
            <div>
                <label>Colore badge</label>
                <input type="text" name="badge_color" value="{{ old('badge_color') }}" placeholder="#1d4ed8">
            </div>
            <div class="plan-checkbox-row">
                <input type="checkbox" id="new-sell" name="can_sell_products" value="1" @checked(old('can_sell_products'))>
                <label for="new-sell">Puo' vendere prodotti</label>
            </div>
            <div class="plan-checkbox-row">
                <input type="checkbox" id="new-ky" name="allow_ky_payment" value="1" @checked(old('allow_ky_payment', true))>
                <label for="new-ky">Pagabile in KY</label>
            </div>
            <div class="plan-checkbox-row">
                <input type="checkbox" id="new-active" name="is_active" value="1" @checked(old('is_active', true))>
                <label for="new-active">Attivo</label>
            </div>
        </div>
        <div class="form-actions" style="margin-top:12px;">
            <button class="cta" type="submit">Crea piano</button>
        </div>
    </form>
</div>
@endsection
