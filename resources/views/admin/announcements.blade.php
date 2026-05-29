@extends('layouts.app')

@section('title', $pageTitle ?? 'Moderazione Annunci')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-8">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Moderazione Annunci</h1>
        <a href="{{ route('portal.announcements') }}"
           class="text-sm text-blue-600 hover:text-blue-800">← Vista portale</a>
    </div>

    {{-- Flash --}}
    @if(session('portal_success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-green-800 text-sm">
            {{ session('portal_success') }}
        </div>
    @endif

    {{-- Filtri --}}
    <form method="GET" action="{{ route('admin.announcements.index') }}"
          class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-6 flex flex-wrap gap-3">
        <input type="text" name="q" value="{{ request('q') }}"
               placeholder="Cerca titolo o azienda…"
               class="flex-1 min-w-48 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        <select name="type"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">Tutti i tipi</option>
            @foreach($types as $key => $label)
                <option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="status"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">Tutti gli stati</option>
            @foreach($statuses as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit"
                class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-blue-700">
            Cerca
        </button>
        @if(request('q') || request('status') || request('type'))
            <a href="{{ route('admin.announcements.index') }}"
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
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">Annuncio</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">Azienda</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-700">Tipo</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">Settore</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-700">Stato</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-700">Evidenza</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-700">Data</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-700">Azioni</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($announcements as $ann)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ route('portal.announcements.show', $ann) }}"
                               target="_blank"
                               class="font-medium text-blue-700 hover:underline line-clamp-1 max-w-xs block">
                                {{ $ann->title }}
                            </a>
                            <span class="text-xs text-gray-400">{{ $ann->views_count }} visite</span>
                        </td>
                        <td class="px-4 py-3 text-gray-700">{{ $ann->company->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full
                                {{ $ann->type === 'offer' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }}">
                                {{ $ann->type_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $ann->sector_label }}</td>
                        <td class="px-4 py-3 text-center">
                            <form method="POST"
                                  action="{{ route('admin.announcements.status', $ann) }}"
                                  class="inline-block">
                                @csrf
                                <select name="status" onchange="this.form.submit()"
                                        class="text-xs rounded-full px-2 py-1 border font-medium focus:outline-none
                                            {{ match($ann->status) {
                                                'active'    => 'bg-green-50 border-green-300 text-green-800',
                                                'suspended' => 'bg-red-50 border-red-300 text-red-800',
                                                'expired'   => 'bg-gray-50 border-gray-300 text-gray-600',
                                                'draft'     => 'bg-yellow-50 border-yellow-300 text-yellow-800',
                                                default     => 'bg-gray-50 border-gray-300 text-gray-600',
                                            } }}">
                                    @foreach($statuses as $s)
                                        <option value="{{ $s }}" @selected($ann->status === $s)>{{ ucfirst($s) }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($ann->featured)
                                <span class="text-yellow-500 font-bold">★</span>
                            @else
                                <span class="text-gray-300">☆</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-400 text-xs whitespace-nowrap">
                            {{ $ann->created_at->format('d/m/Y') }}
                            @if($ann->expires_at)
                                <br>
                                <span class="{{ $ann->is_expired ? 'text-red-500' : '' }}">
                                    scade {{ $ann->expires_at->format('d/m/Y') }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <a href="{{ route('portal.announcements.edit', $ann) }}"
                                   class="text-blue-600 hover:text-blue-800 text-xs font-medium">
                                    Modifica
                                </a>
                                <form method="POST"
                                      action="{{ route('portal.announcements.destroy', $ann) }}"
                                      onsubmit="return confirm('Eliminare questo annuncio?')">
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
                        <td colspan="8" class="px-4 py-12 text-center text-gray-400">
                            Nessun annuncio trovato.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($announcements->hasPages())
        <div class="mt-4">
            {{ $announcements->withQueryString()->links() }}
        </div>
    @endif

</div>
@endsection
