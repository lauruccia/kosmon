@extends('layouts.portal')

@section('title', $pageTitle ?? 'Moderazione Shop')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-8">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Moderazione Shop</h1>
        <a href="{{ route('portal.shop') }}"
           class="text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1">
            ← Vista portale
        </a>
    </div>

    {{-- Flash --}}
    @if(session('portal_success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-green-800 text-sm">
            {{ session('portal_success') }}
        </div>
    @endif

    {{-- Filtri --}}
    <form method="GET" action="{{ route('admin.listings.index') }}"
          class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-6 flex flex-wrap gap-3">
        <input type="text" name="q" value="{{ request('q') }}"
               placeholder="Cerca titolo o azienda…"
               class="flex-1 min-w-48 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        <select name="status"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">Tutti gli stati</option>
            @foreach($statuses as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>
                    {{ ucfirst($s) }}
                </option>
            @endforeach
        </select>
        <button type="submit"
                class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-blue-700">
            Cerca
        </button>
        @if(request('q') || request('status'))
            <a href="{{ route('admin.listings.index') }}"
               class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                Reset
            </a>
        @endif
    </form>

    {{-- Tabella --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">Prodotto</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">Azienda</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">Categoria</th>
                    <th class="text-right px-4 py-3 font-semibold text-gray-700">Prezzo</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-700">Stato</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-700">In evidenza</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">Pubblicato da</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">Data</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-700">Azioni</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($listings as $listing)
                    <tr class="hover:bg-gray-50 transition-colors">
                        {{-- Prodotto --}}
                        <td class="px-4 py-3">
                            <a href="{{ route('portal.shop.show', $listing) }}"
                               target="_blank"
                               class="font-medium text-blue-700 hover:underline line-clamp-1 max-w-xs block">
                                {{ $listing->title }}
                            </a>
                            <span class="text-xs text-gray-400">{{ $listing->views_count }} visite</span>
                        </td>

                        {{-- Azienda --}}
                        <td class="px-4 py-3 text-gray-700">
                            {{ $listing->company->name ?? '—' }}
                        </td>

                        {{-- Categoria --}}
                        <td class="px-4 py-3 text-gray-500 text-xs">
                            {{ $listing->category_label }}
                        </td>

                        {{-- Prezzo --}}
                        <td class="px-4 py-3 text-right font-semibold text-gray-800">
                            {{ number_format($listing->price_ky) }} KY
                        </td>

                        {{-- Stato --}}
                        <td class="px-4 py-3 text-center">
                            <form method="POST"
                                  action="{{ route('admin.listings.status', $listing) }}"
                                  class="inline-block">
                                @csrf
                                <select name="status" onchange="this.form.submit()"
                                        class="text-xs rounded-full px-2 py-1 border font-medium focus:outline-none
                                            {{ match($listing->status) {
                                                'active'    => 'bg-green-50 border-green-300 text-green-800',
                                                'suspended' => 'bg-red-50 border-red-300 text-red-800',
                                                'expired'   => 'bg-gray-50 border-gray-300 text-gray-600',
                                                'draft'     => 'bg-yellow-50 border-yellow-300 text-yellow-800',
                                                default     => 'bg-gray-50 border-gray-300 text-gray-600',
                                            } }}">
                                    @foreach($statuses as $s)
                                        <option value="{{ $s }}" @selected($listing->status === $s)>
                                            {{ ucfirst($s) }}
                                        </option>
                                    @endforeach
                                </select>
                            </form>
                        </td>

                        {{-- In evidenza --}}
                        <td class="px-4 py-3 text-center">
                            @if($listing->featured)
                                <span class="text-yellow-500 font-bold" title="In evidenza">★</span>
                            @else
                                <span class="text-gray-300">☆</span>
                            @endif
                        </td>

                        {{-- Pubblicato da --}}
                        <td class="px-4 py-3 text-gray-500 text-xs">
                            {{ $listing->createdByUser->name ?? '—' }}
                        </td>

                        {{-- Data --}}
                        <td class="px-4 py-3 text-gray-400 text-xs whitespace-nowrap">
                            {{ $listing->created_at->format('d/m/Y') }}
                            @if($listing->expires_at)
                                <br>
                                <span class="{{ $listing->is_expired ? 'text-red-500' : 'text-gray-400' }}">
                                    scade {{ $listing->expires_at->format('d/m/Y') }}
                                </span>
                            @endif
                        </td>

                        {{-- Azioni --}}
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <a href="{{ route('portal.shop.edit', $listing) }}"
                                   class="text-blue-600 hover:text-blue-800 text-xs font-medium">
                                    Modifica
                                </a>
                                <form method="POST"
                                      action="{{ route('portal.shop.destroy', $listing) }}"
                                      onsubmit="return confirm('Eliminare questo prodotto?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="text-red-600 hover:text-red-800 text-xs font-medium">
                                        Elimina
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center text-gray-400">
                            Nessun prodotto trovato.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Paginazione --}}
    @if($listings->hasPages())
        <div class="mt-4">
            {{ $listings->withQueryString()->links() }}
        </div>
    @endif

</div>
@endsection
