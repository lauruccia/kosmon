@extends('layouts.portal')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-gray-400 mb-6 flex items-center gap-2">
        <a href="{{ route('portal.announcements') }}" class="hover:text-blue-600">Annunci</a>
        <span>/</span>
        <span class="text-gray-600">{{ Str::limit($announcement->title, 60) }}</span>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        {{-- Colonna principale --}}
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 mb-6">

                {{-- Badge --}}
                <div class="flex items-center gap-3 mb-4">
                    <span class="text-sm font-semibold px-3 py-1 rounded-full
                        {{ $announcement->type === 'offer' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }}">
                        {{ $announcement->type_label }}
                    </span>
                    <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full">
                        {{ $announcement->sector_label }}
                    </span>
                    @if($announcement->featured)
                        <span class="text-xs text-yellow-600 bg-yellow-50 px-2 py-1 rounded-full">★ In evidenza</span>
                    @endif
                </div>

                <h1 class="text-2xl font-bold text-gray-900 mb-4">{{ $announcement->title }}</h1>

                <div class="prose prose-sm max-w-none text-gray-700 leading-relaxed">
                    {!! nl2br(e($announcement->body)) !!}
                </div>

                {{-- Metadati --}}
                <div class="mt-6 pt-4 border-t border-gray-100 flex flex-wrap gap-4 text-sm text-gray-500">
                    <span>Pubblicato {{ $announcement->created_at->diffForHumans() }}</span>
                    <span>{{ $announcement->views_count }} visualizzazioni</span>
                    @if($announcement->expires_at)
                        <span class="{{ $announcement->is_expired ? 'text-red-500' : '' }}">
                            Scade il {{ $announcement->expires_at->format('d/m/Y') }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- Errore campo risposta --}}
            @if($errors->has('message'))
                <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
                    {{ $errors->first('message') }}
                </div>
            @endif

            {{-- Azioni proprietario --}}
            @if($isOwner)
                <div class="flex gap-3 mb-6">
                    <a href="{{ route('portal.announcements.edit', $announcement) }}"
                       class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-blue-700">
                        Modifica
                    </a>
                    <form method="POST"
                          action="{{ route('portal.announcements.destroy', $announcement) }}"
                          onsubmit="return confirm('Eliminare questo annuncio?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="border border-red-200 text-red-600 rounded-lg px-4 py-2 text-sm font-medium hover:bg-red-50">
                            Elimina
                        </button>
                    </form>
                </div>
            @endif

            {{-- Risposte ricevute (solo owner) --}}
            @if($isOwner)
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 mb-6">
                <h2 class="text-base font-bold text-gray-800 mb-4 flex items-center gap-2">
                    💬 Risposte ricevute
                    @if($replies->count() > 0)
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700">{{ $replies->count() }}</span>
                    @endif
                </h2>
                @forelse($replies as $rep)
                <div class="border-b border-gray-100 pb-4 mb-4 last:border-0 last:mb-0 last:pb-0">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-xs">
                                {{ mb_substr($rep->company->name ?? '?', 0, 1) }}
                            </div>
                            <div>
                                <span class="font-semibold text-sm text-gray-900">{{ $rep->company->name ?? '—' }}</span>
                                <span class="text-xs text-gray-400 ml-2">{{ $rep->user->name ?? '' }}</span>
                            </div>
                        </div>
                        <span class="text-xs text-gray-400">{{ $rep->created_at->locale('it')->isoFormat('D MMM YYYY, HH:mm') }}</span>
                    </div>
                    <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-line pl-10">{{ $rep->message }}</p>
                </div>
                @empty
                <p class="text-sm text-gray-400 italic">Nessuna risposta ancora. Quando qualcuno risponde, ricevi una notifica email.</p>
                @endforelse
            </div>
            @endif

            {{-- Form risposta (non-owner) --}}
            @if(! $isOwner)
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 mb-6">
                <h2 class="text-base font-bold text-gray-800 mb-1">Rispondi all'annuncio</h2>
                <p class="text-sm text-gray-500 mb-4">Il pubblicatore riceverà la tua risposta via email e potrà contattarti.</p>
                <form method="POST" action="{{ route('portal.announcements.reply', $announcement) }}">
                    @csrf
                    <textarea name="message"
                              rows="4"
                              maxlength="1500"
                              placeholder="Scrivi il tuo messaggio... (min. 10 caratteri)"
                              class="w-full border border-gray-200 rounded-xl p-3 text-sm text-gray-800 focus:outline-none focus:border-blue-500 resize-none"
                              style="font-family:inherit;">{{ old('message') }}</textarea>
                    <div class="flex items-center justify-between mt-3">
                        <span class="text-xs text-gray-400">Il tuo nome e azienda saranno inclusi nella notifica</span>
                        <button type="submit"
                                class="bg-blue-600 text-white rounded-lg px-5 py-2 text-sm font-semibold hover:bg-blue-700">
                            Invia risposta
                        </button>
                    </div>
                </form>
            </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-4">

            {{-- Card azienda --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <h3 class="font-semibold text-gray-800 mb-3">Pubblicato da</h3>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-sm">
                        {{ mb_substr($announcement->company->name ?? '?', 0, 1) }}
                    </div>
                    <div>
                        <p class="font-medium text-gray-900 text-sm">{{ $announcement->company->name ?? '—' }}</p>
                        @if($announcement->company->sector ?? false)
                            <p class="text-xs text-gray-400">{{ $announcement->company->sector }}</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Contatti --}}
            @if($announcement->contact_info)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="font-semibold text-gray-800 mb-2">Contatti</h3>
                    <p class="text-sm text-gray-700 break-all">{{ $announcement->contact_info }}</p>
                </div>
            @endif

            {{-- Link torna alla lista --}}
            <a href="{{ route('portal.announcements') }}"
               class="block text-center text-sm text-blue-600 hover:text-blue-800 mt-2">
                ← Torna agli annunci
            </a>
        </div>
    </div>

    {{-- Annunci correlati --}}
    @if($related->isNotEmpty())
        <section class="mt-10">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Annunci simili</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach($related as $rel)
                    <a href="{{ route('portal.announcements.show', $rel) }}"
                       class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 hover:shadow-md transition-shadow group">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full
                                {{ $rel->type === 'offer' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }}">
                                {{ $rel->type_label }}
                            </span>
                        </div>
                        <h3 class="font-medium text-gray-900 text-sm line-clamp-2 group-hover:text-blue-700">
                            {{ $rel->title }}
                        </h3>
                        <p class="text-xs text-gray-400 mt-1">{{ $rel->company->name ?? '' }}</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

</div>
@endsection
