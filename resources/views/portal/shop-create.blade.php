@extends('layouts.portal')

@section('content')
<section class="page-intro--row page-intro">
    <span class="eyebrow">{{ $editingListing ? 'Modifica prodotto' : 'Nuovo prodotto' }}</span>
    <h2>{{ $editingListing ? 'Modifica: ' . $editingListing->title : 'Pubblica nello shop' }}</h2>
    <p>Inserisci le informazioni del prodotto o servizio che vuoi offrire nel circuito KMoney.</p>
</section>

<div style="max-width:720px;">
<section class="card light-card">
    @if($errors->any())
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
        @foreach($errors->all() as $error)
            <p style="color:#991b1b;font-size:14px;margin:2px 0;">• {{ $error }}</p>
        @endforeach
    </div>
    @endif

    <form method="POST"
          action="{{ $editingListing ? route('portal.shop.update', $editingListing) : route('portal.shop.store') }}"
          enctype="multipart/form-data">
        @csrf
        @if($editingListing) @method('PUT') @endif

        <div class="field-grid" style="grid-template-columns:1fr;gap:18px;">

            <div>
                <label class="field-label">Titolo prodotto / servizio *</label>
                <input type="text" name="title" value="{{ old('title', $editingListing?->title) }}"
                    required maxlength="160" placeholder="es. Consulenza SEO mensile"
                    class="field-input">
            </div>

            <div>
                <label class="field-label">Descrizione *</label>
                <textarea name="description" required maxlength="2000" rows="5"
                    placeholder="Descrivi il prodotto/servizio in dettaglio: cosa include, modalità di erogazione, eventuali prerequisiti..."
                    class="field-input" style="resize:vertical;">{{ old('description', $editingListing?->description) }}</textarea>
                <small style="color:#94a3b8;font-size:12px;">Massimo 2000 caratteri</small>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <label class="field-label">Categoria *</label>
                    <select name="category" required class="field-input">
                        @foreach($categories as $slug => $label)
                            <option value="{{ $slug }}" @selected(old('category', $editingListing?->category) === $slug)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label">Prezzo totale (KY) *</label>
                    <input type="number" name="price_ky" min="1" max="9999999"
                        value="{{ old('price_ky', $editingListing?->price_ky) }}"
                        required placeholder="1000" class="field-input">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                        Il prezzo in KY rappresenta il valore totale dell'offerta.
                    </div>
                </div>
                <div>
                    <label class="field-label">Mix pagamento KY/EUR *</label>

                    @php
                        $allowed = $allowedKyPercentages ?? \App\Models\Listing::KY_PERCENTAGES;
                        $required = $requiredKyPercentage ?? null;
                        $currentPct = old('ky_percentage', $editingListing?->ky_percentage ?? 100);
                    @endphp

                    @if($required !== null)
                        {{-- Forzato: saldo negativo --}}
                        <input type="hidden" name="ky_percentage" value="100">
                        <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:13px;color:#713f12;">
                            <strong>100% KY obbligatorio</strong> — il tuo saldo reale è
                            <strong>{{ number_format((int) $currentAccount->available_balance, 0, ',', '.') }} KY</strong>
                            (negativo). Devi incassare KY per recuperare il saldo prima di poter offrire un mix EUR.<br>
                            <span style="font-size:12px;opacity:.8;">Nota: il saldo "Disponibile" che vedi in dashboard include l'eventuale fido/massimale concesso, ma il saldo reale del circuito è negativo.</span>
                        </div>
                    @else
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            @foreach($allowed as $pct)
                                @php
                                    $eur = 100 - $pct;
                                    $label = $pct === 100 ? '100% KY' : ($pct === 0 ? '100% EUR' : "{$pct}% KY + {$eur}% EUR");
                                @endphp
                                <label style="cursor:pointer;">
                                    <input type="radio" name="ky_percentage" value="{{ $pct }}"
                                        {{ (int)$currentPct === $pct ? 'checked' : '' }}
                                        style="display:none;" class="ky-pct-radio">
                                    <span class="ky-pct-btn"
                                        style="display:inline-block;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;
                                               border:2px solid var(--border);cursor:pointer;transition:all .15s;
                                               {{ (int)$currentPct === $pct ? 'background:var(--primary);color:#fff;border-color:var(--primary);' : 'background:var(--card);color:var(--text);' }}">
                                        {{ $label }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">
                            La quota EUR viene saldata direttamente tra acquirente e venditore fuori dal circuito.
                        </div>
                    @endif
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <label class="field-label">Nota consegna / erogazione</label>
                    <input type="text" name="delivery_note" maxlength="120"
                        value="{{ old('delivery_note', $editingListing?->delivery_note) }}"
                        placeholder="es. Consegna in 48h" class="field-input">
                </div>
                <div>
                    <label class="field-label">Scadenza offerta</label>
                    <input type="date" name="expires_at"
                        value="{{ old('expires_at', $editingListing?->expires_at?->format('Y-m-d')) }}"
                        min="{{ now()->addDay()->format('Y-m-d') }}"
                        class="field-input">
                </div>
            </div>

            <div>
                <label class="field-label">Contatto diretto (email o telefono)</label>
                <input type="text" name="contact_info" maxlength="200"
                    value="{{ old('contact_info', $editingListing?->contact_info) }}"
                    placeholder="es. commerciale@azienda.it o +39 320 ..." class="field-input">
                <small style="color:#94a3b8;font-size:12px;">Visibile agli utenti del circuito interessati al prodotto</small>
            </div>

            {{-- ── Immagini ────────────────────────────────────────────────── --}}
            <div>
                <label class="field-label">Foto prodotto</label>

                {{-- Immagini esistenti (solo in modifica) --}}
                @if($editingListing && $editingListing->image_urls)
                <div id="existing-images" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                    @foreach($editingListing->images as $path)
                    <div class="img-thumb" style="position:relative;">
                        <img src="{{ Storage::disk('public')->url($path) }}"
                            alt="Immagine prodotto"
                            style="width:90px;height:90px;object-fit:cover;border-radius:8px;display:block;">
                        <form method="POST"
                              action="{{ route('portal.shop.image.destroy', $editingListing) }}"
                              style="position:absolute;top:3px;right:3px;"
                              onsubmit="return confirm('Eliminare questa immagine?')">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="path" value="{{ $path }}">
                            <button type="submit"
                                style="background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:50%;
                                       width:20px;height:20px;font-size:13px;line-height:1;cursor:pointer;">×</button>
                        </form>
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- Carica nuove immagini --}}
                <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp"
                    class="field-input" style="padding:8px;">
                <small style="color:#94a3b8;font-size:12px;">
                    Massimo 6 immagini · JPG, PNG, WebP · max 3 MB ciascuna
                </small>
            </div>

            {{-- ── Pulsante submit ─────────────────────────────────────────── --}}
            <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:8px;">
                <a href="{{ route('portal.shop') }}" class="btn-outline">Annulla</a>
                <button type="submit" class="cta">
                    {{ $editingListing ? 'Salva modifiche' : 'Pubblica prodotto' }}
                </button>
            </div>

        </div>
    </form>
</section>
</div>

@push('scripts')
<script>
// Toggle radio button stile pill per il selettore KY/EUR
document.querySelectorAll('.ky-pct-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.ky-pct-btn').forEach(function(btn) {
            btn.style.background = 'var(--card)';
            btn.style.color = 'var(--text)';
            btn.style.borderColor = 'var(--border)';
        });
        if (this.checked) {
            var btn = this.nextElementSibling;
            btn.style.background = 'var(--primary)';
            btn.style.color = '#fff';
            btn.style.borderColor = 'var(--primary)';
        }
    });
});
</script>
@endpush

@endsection
