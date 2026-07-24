@extends('layouts.portal')

@section('title', $pageTitle ?? 'Nuovo prodotto')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-8">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-6 gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Moderazione shop</p>
            <h1 class="text-2xl font-bold text-gray-900">Nuovo prodotto per conto di un'azienda</h1>
            <p class="text-sm text-gray-500 mt-1">Pubblica un prodotto assegnandolo a un'azienda del circuito: comparirà nello shop esattamente come se l'avesse pubblicato lei stessa.</p>
        </div>
        <a href="{{ route('admin.listings.index') }}" class="text-sm text-blue-600 hover:text-blue-800 whitespace-nowrap">
            ← Torna alla moderazione
        </a>
    </div>

    {{-- Errori --}}
    @if($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3">
            @foreach($errors->all() as $error)
                <p class="text-red-800 text-sm">• {{ $error }}</p>
            @endforeach
        </div>
    @endif
    @if(session('portal_error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-red-800 text-sm">
            {{ session('portal_error') }}
        </div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <form method="POST" action="{{ route('admin.listings.store') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf

            {{-- Azienda --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Azienda *</label>
                <select name="company_id" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">— Seleziona azienda —</option>
                    @foreach($companies as $company)
                        <option value="{{ $company->id }}" @selected((int) old('company_id') === $company->id)>{{ $company->name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-400 mt-1">
                    Il prodotto verrà assegnato a questa azienda. Se il suo saldo è negativo o al tetto massimo,
                    valgono le stesse regole KY/EUR che si applicherebbero se lo pubblicasse lei: il salvataggio
                    darà errore in caso di percentuale non consentita.
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Titolo prodotto / servizio *</label>
                <input type="text" name="title" value="{{ old('title') }}" required maxlength="160"
                    placeholder="es. Consulenza SEO mensile"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Descrizione *</label>
                <textarea name="description" required maxlength="2000" rows="5"
                    placeholder="Descrivi il prodotto/servizio in dettaglio: cosa include, modalità di erogazione, eventuali prerequisiti..."
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('description') }}</textarea>
                <p class="text-xs text-gray-400 mt-1">Massimo 2000 caratteri</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Categoria *</label>
                    <select name="category" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        @foreach($categories as $slug => $label)
                            <option value="{{ $slug }}" @selected(old('category') === $slug)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Prezzo totale (KY) *</label>
                    <input type="number" name="price_ky" min="1" max="9999999" value="{{ old('price_ky') }}" required
                        placeholder="1000"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            {{-- Mix KY/EUR --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mix pagamento KY/EUR *</label>
                <div class="flex gap-2 flex-wrap">
                    @foreach(\App\Models\Listing::KY_PERCENTAGES as $pct)
                        @php
                            $eur = 100 - $pct;
                            $label = $pct === 100 ? '100% KY' : ($pct === 0 ? '100% EUR' : "{$pct}% KY + {$eur}% EUR");
                            $checked = (int) old('ky_percentage', 100) === $pct;
                        @endphp
                        <label class="cursor-pointer">
                            <input type="radio" name="ky_percentage" value="{{ $pct }}" class="hidden peer" {{ $checked ? 'checked' : '' }}
                                onchange="document.querySelectorAll('.admin-ky-pct-btn').forEach(b=>{b.classList.remove('bg-blue-600','text-white','border-blue-600');b.classList.add('border-gray-300','text-gray-700')}); this.nextElementSibling.classList.add('bg-blue-600','text-white','border-blue-600'); this.nextElementSibling.classList.remove('border-gray-300','text-gray-700');">
                            <span class="admin-ky-pct-btn inline-block px-3 py-1.5 rounded-full text-xs font-semibold border-2 {{ $checked ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-700' }}">
                                {{ $label }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Disponibilità --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Disponibilità *</label>
                @php $stockMode = old('stock_mode', 'unlimited'); @endphp
                <div class="flex gap-4 items-center text-sm">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="stock_mode" value="unlimited" {{ $stockMode === 'unlimited' ? 'checked' : '' }}
                            onchange="document.getElementById('admin-stock-qty').style.display='none'">
                        Illimitata
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="stock_mode" value="limited" {{ $stockMode === 'limited' ? 'checked' : '' }}
                            onchange="document.getElementById('admin-stock-qty').style.display='block'">
                        Scorta limitata
                    </label>
                </div>
                <div id="admin-stock-qty" style="{{ $stockMode === 'limited' ? '' : 'display:none;' }}" class="mt-2">
                    <input type="number" name="stock_quantity" min="0" max="999999" value="{{ old('stock_quantity') }}"
                        placeholder="es. 20" class="w-40 rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nota consegna / erogazione</label>
                    <input type="text" name="delivery_note" maxlength="120" value="{{ old('delivery_note') }}"
                        placeholder="es. Consegna in 48h"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Scadenza offerta</label>
                    <input type="date" name="expires_at" min="{{ now()->addDay()->format('Y-m-d') }}"
                        value="{{ old('expires_at') }}"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Contatto diretto (email o telefono)</label>
                <input type="text" name="contact_info" maxlength="200" value="{{ old('contact_info') }}"
                    placeholder="es. commerciale@azienda.it o +39 320 ..."
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="text-xs text-gray-400 mt-1">Visibile agli utenti del circuito interessati al prodotto</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Foto prodotto</label>
                <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="text-sm">
                <p class="text-xs text-gray-400 mt-1">Massimo 6 immagini · JPG, PNG, WebP · max 3 MB ciascuna</p>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <a href="{{ route('admin.listings.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                    Annulla
                </a>
                <button type="submit" class="bg-blue-600 text-white rounded-lg px-5 py-2 text-sm font-medium hover:bg-blue-700">
                    Pubblica prodotto
                </button>
            </div>

        </form>
    </div>
</div>
@endsection
