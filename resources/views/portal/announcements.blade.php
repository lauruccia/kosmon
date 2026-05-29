@extends('layouts.portal')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-8">

    {{-- Annunci in evidenza --}}
    @if($featuredAnnouncements->isNotEmpty())
        <section class="mb-8">
            <h2 class="text-sm font-semibold text-yellow-600 uppercase tracking-wide mb-3">★ In evidenza</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach($featuredAnnouncements as $ann)
                    <a href="{{ route('portal.announcements.show', $ann) }}"
                       class="bg-white rounded-xl border border-yellow-200 shadow-sm p-4 hover:shadow-md transition-shadow group">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full
                                {{ $ann->type === 'offer' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }}">
                                {{ $ann->type_label }}
                            </span>
                            <span class="text-xs text-gray-400">{{ $ann->sector_label }}</span>
                        </div>
                        <h3 class="font-semibold text-gray-900 text-sm line-clamp-2 group-hover:text-blue-700 transition-colors">
                            {{ $ann->title }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-1">{{ $ann->company->name ?? '' }}</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Filtri --}}
    <form method="GET" action="{{ route('portal.announcements') }}"
          class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-6 flex flex-wrap gap-3">
        <input type="text" name="q" value="{{ $searchQuery }}"
               placeholder="Cerca titolo, testo o azienda…"
               class="flex-1 min-w-48 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        <select name="type"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">Tutti i tipi</option>
            @foreach($types as $key => $label)
                <option value="{{ $key }}" @selected($selectedType === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="sector"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">Tutti i settori</option>
            @foreach($sectors as $key => $label)
                <option value="{{ $key }}" @selected($selectedSector === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit"
                class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-blue-700">
            Cerca
        </button>
        @if($searchQuery || $selectedType || $selectedSector)
            <a href="{{ route('portal.announcements') }}"
               class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                Reset
            </a>
        @endif
        <div class="ml-auto flex gap-2 items-center flex-wrap">
            <a href="{{ route('portal.announcements.create') }}"
               class="cta whitespace-nowrap" style="padding:8px 18px;font-size:14px;">
                + Pubblica annuncio
            </a>
            <a href="{{ route('portal.shop') }}"
               class="cta secondary whitespace-nowrap" style="padding:8px 18px;font-size:14px;">
                Vai allo shop
            </a>
        </div>
    </form>

    {{-- Griglia annunci --}}
    @if($announcements->isEmpty())
        <div class="text-center py-20 text-gray-400">
            <div class="text-5xl mb-4">📢</div>
            <p class="text-lg font-medium">Nessun annuncio trovato</p>
            <p class="text-sm mt-1">
                @if($searchQuery || $selectedType || $selectedSector)
                    Prova a modificare i filtri di ricerca.
                @else
                    Sii il primo a pubblicare un annuncio nel circuito.
                @endif
            </p>
            <a href="{{ route('portal.announcements.create') }}"
               class="inline-block mt-4 bg-blue-700 rounded-lg px-6 py-3 text-base font-bold hover:bg-blue-800 shadow-md tracking-wide"
               style="color:#ffffff !important;">
                Pubblica il primo annuncio
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
            @foreach($announcements as $ann)
                <a href="{{ route('portal.announcements.show', $ann) }}"
                   class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow p-5 flex flex-col group">
                    {{-- Badge tipo + settore --}}
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full
                            {{ $ann->type === 'offer' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }}">
                            {{ $ann->type_label }}
                        </span>
                        <span class="text-xs text-gray-400">{{ $ann->sector_label }}</span>
                    </div>

                    {{-- Titolo --}}
                    <h3 class="font-semibold text-gray-900 text-base line-clamp-2 group-hover:text-blue-700 transition-colors mb-2">
                        {{ $ann->title }}
                    </h3>

                    {{-- Corpo estratto --}}
                    <p class="text-sm text-gray-500 line-clamp-3 flex-1">
                        {{ Str::limit(strip_tags($ann->body), 140) }}
                    </p>

                    {{-- Footer --}}
                    <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-100 text-xs text-gray-400">
                        <span class="font-medium text-gray-600">{{ $ann->company->name ?? '' }}</span>
                        <span>{{ $ann->created_at->diffForHumans() }}</span>
                    </div>

                    @if($ann->featured)
                        <span class="absolute top-3 right-3 text-yellow-400 text-xs">★</span>
                    @endif
                </a>
            @endforeach
        </div>

        {{-- Paginazione --}}
        @if($announcements->hasPages())
            <div class="flex justify-center">
                {{ $announcements->links() }}
            </div>
        @endif
    @endif

</div>
@endsection
