@extends('layouts.portal')

@section('content')
<div class="max-w-2xl mx-auto px-4 py-8">

    {{-- Header --}}
    <div class="mb-6">
        <a href="{{ route('portal.announcements') }}"
           class="text-sm text-blue-600 hover:text-blue-800">← Torna agli annunci</a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2">{{ $pageTitle }}</h1>
    </div>

    @php
        $ann    = $editingAnnouncement;
        $action = $ann ? route('portal.announcements.update', $ann) : route('portal.announcements.store');
        $method = $ann ? 'PUT' : 'POST';
    @endphp

    <form method="POST" action="{{ $action }}" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
        @csrf
        @method($method)

        {{-- Tipo --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Tipo annuncio *</label>
            <div class="flex gap-4">
                @foreach($types as $key => $label)
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="type" value="{{ $key }}"
                               class="accent-blue-600"
                               @checked(old('type', $ann?->type ?? 'offer') === $key)>
                        <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @error('type')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Titolo --}}
        <div>
            <label for="title" class="block text-sm font-semibold text-gray-700 mb-1">
                Titolo <span class="text-red-500">*</span>
            </label>
            <input type="text" id="title" name="title"
                   value="{{ old('title', $ann?->title) }}"
                   maxlength="160"
                   placeholder="Es. Cerco servizi di grafica per campagna promozionale"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 @error('title') border-red-400 @enderror">
            @error('title')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Corpo --}}
        <div>
            <label for="body" class="block text-sm font-semibold text-gray-700 mb-1">
                Descrizione <span class="text-red-500">*</span>
            </label>
            <textarea id="body" name="body" rows="6"
                      maxlength="3000"
                      placeholder="Descrivi la tua offerta o richiesta nel dettaglio: quantità, tempi, modalità di fornitura…"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 @error('body') border-red-400 @enderror">{{ old('body', $ann?->body) }}</textarea>
            @error('body')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Settore --}}
        <div>
            <label for="sector" class="block text-sm font-semibold text-gray-700 mb-1">
                Settore <span class="text-red-500">*</span>
            </label>
            <select id="sector" name="sector"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 @error('sector') border-red-400 @enderror">
                @foreach($sectors as $key => $label)
                    <option value="{{ $key }}" @selected(old('sector', $ann?->sector) === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('sector')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Contatti --}}
        <div>
            <label for="contact_info" class="block text-sm font-semibold text-gray-700 mb-1">
                Informazioni di contatto
                <span class="text-gray-400 font-normal text-xs">(opzionale)</span>
            </label>
            <input type="text" id="contact_info" name="contact_info"
                   value="{{ old('contact_info', $ann?->contact_info) }}"
                   maxlength="200"
                   placeholder="email@azienda.it oppure +39 123 456 789"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 @error('contact_info') border-red-400 @enderror">
            @error('contact_info')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Scadenza --}}
        <div>
            <label for="expires_at" class="block text-sm font-semibold text-gray-700 mb-1">
                Scadenza annuncio
                <span class="text-gray-400 font-normal text-xs">(opzionale)</span>
            </label>
            <input type="date" id="expires_at" name="expires_at"
                   value="{{ old('expires_at', $ann?->expires_at?->format('Y-m-d')) }}"
                   min="{{ date('Y-m-d', strtotime('+1 day')) }}"
                   class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 @error('expires_at') border-red-400 @enderror">
            @error('expires_at')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Submit --}}
        <div class="flex items-center gap-4 pt-2">
            <button type="submit"
                    class="bg-blue-600 text-white rounded-lg px-6 py-2.5 font-semibold text-sm hover:bg-blue-700 transition-colors">
                {{ $ann ? 'Aggiorna annuncio' : 'Pubblica annuncio' }}
            </button>
            <a href="{{ route('portal.announcements') }}"
               class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
        </div>
    </form>
</div>
@endsection
