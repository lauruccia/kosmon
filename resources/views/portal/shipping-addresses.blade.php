@extends('layouts.portal')

@section('content')
{{--
    La rubrica degli indirizzi di spedizione (fase A-bis, 26/08/2026).

    Fino a 10 indirizzi per conto, uno predefinito. Il tetto non e' tecnico:
    e' il punto oltre il quale la tendina in cassa smette di essere leggibile.
    A differenza di Shopify — che ne salva quanti ne vuoi ma in cassa te ne
    mostra solo i 5 piu' recenti — qui TUTTI quelli salvati sono scegliibili al
    momento di pagare.
--}}
@php
    $ritornoParam = $ritorno ? ['redirect_to' => $ritorno] : [];
@endphp

<div style="margin-bottom:16px;">
    <a href="{{ $ritorno ?: route('portal.dashboard') }}" class="shop-back-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        {{ $ritorno ? 'Torna alla cassa' : 'Torna alla dashboard' }}
    </a>
</div>

<section class="card light-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div>
            <span class="eyebrow">Spedizioni</span>
            <h2 style="font-size:20px;font-weight:700;color:#10263d;margin:4px 0 6px;">I tuoi indirizzi</h2>
            <p class="subtle" style="font-size:13px;margin:0;">
                Puoi salvarne fino a {{ $tetto }}. Quello predefinito è già scelto in cassa, ma lì puoi cambiarlo con un clic.
            </p>
        </div>
        <span class="pill">{{ $indirizzi->count() }} / {{ $tetto }}</span>
    </div>
</section>

@forelse($indirizzi as $indirizzo)
<section class="card light-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
        <div style="min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                @if($indirizzo->label)
                    <strong style="color:#10263d;font-size:15px;">{{ $indirizzo->label }}</strong>
                @endif
                @if($indirizzo->is_default)
                    <span class="pill" style="background:#dcfce7;color:#166534;">Predefinito</span>
                @endif
            </div>
            <p style="font-size:13.5px;color:#334155;line-height:1.6;margin:0;">
                @foreach($indirizzo->righe as $riga)
                    {{ $riga }}@if(! $loop->last)<br>@endif
                @endforeach
            </p>
        </div>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            @unless($indirizzo->is_default)
            <form method="POST" action="{{ route('portal.shipping-addresses.default', $indirizzo) }}">
                @csrf
                @if($ritorno)<input type="hidden" name="redirect_to" value="{{ $ritorno }}">@endif
                <button type="submit" style="background:none;border:none;color:#0c4a86;font-size:12.5px;font-weight:600;cursor:pointer;padding:4px 2px;">
                    Rendi predefinito
                </button>
            </form>
            @endunless

            <button type="button" style="background:none;border:none;color:#0c4a86;font-size:12.5px;font-weight:600;cursor:pointer;padding:4px 2px;"
                    onclick="document.getElementById('modifica-{{ $indirizzo->id }}').style.display = document.getElementById('modifica-{{ $indirizzo->id }}').style.display === 'none' ? 'block' : 'none';">
                Modifica
            </button>

            <form method="POST" action="{{ route('portal.shipping-addresses.destroy', $indirizzo) }}"
                  onsubmit="return confirm('Eliminare questo indirizzo? Gli ordini già fatti non cambiano.')">
                @csrf
                @method('DELETE')
                @if($ritorno)<input type="hidden" name="redirect_to" value="{{ $ritorno }}">@endif
                <button type="submit" style="background:none;border:none;color:#b91c1c;font-size:12.5px;font-weight:600;cursor:pointer;padding:4px 2px;">
                    Elimina
                </button>
            </form>
        </div>
    </div>

    <div id="modifica-{{ $indirizzo->id }}" style="display:none;margin-top:18px;padding-top:18px;border-top:1px solid #eef2f7;">
        <form method="POST" action="{{ route('portal.shipping-addresses.update', $indirizzo) }}">
            @csrf
            @method('PUT')
            @if($ritorno)<input type="hidden" name="redirect_to" value="{{ $ritorno }}">@endif
            @include('portal.partials.shipping-address-fields', ['indirizzo' => $indirizzo, 'prefissoId' => 'e'.$indirizzo->id])
            <button type="submit" class="cta" style="margin-top:14px;">Salva modifiche</button>
        </form>
    </div>
</section>
@empty
<section class="card light-card" style="text-align:center;padding:36px 24px;">
    <div style="font-size:38px;line-height:1;margin-bottom:10px;">📮</div>
    <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:0 0 6px;">Nessun indirizzo salvato</h3>
    <p class="subtle" style="margin:0;font-size:13.5px;">Aggiungine uno qui sotto: servirà per i prodotti da spedire.</p>
</section>
@endforelse

@if($puoAggiungere)
<section class="card light-card">
    <span class="eyebrow">Nuovo</span>
    <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0 16px;">Aggiungi un indirizzo</h3>

    <form method="POST" action="{{ route('portal.shipping-addresses.store') }}">
        @csrf
        @if($ritorno)<input type="hidden" name="redirect_to" value="{{ $ritorno }}">@endif
        @include('portal.partials.shipping-address-fields', ['indirizzo' => null, 'prefissoId' => 'n'])

        @if($indirizzi->isNotEmpty())
        <label style="display:flex;gap:9px;align-items:center;margin-top:14px;font-size:13.5px;color:#334155;cursor:pointer;">
            <input type="checkbox" name="is_default" value="1" style="width:16px;height:16px;cursor:pointer;">
            Usalo come indirizzo predefinito
        </label>
        @endif

        <button type="submit" class="cta" style="margin-top:16px;">Aggiungi indirizzo</button>
    </form>
</section>
@else
<section class="card light-card" style="border:1px solid #fde68a;background:#fffbeb;">
    <p style="font-size:13px;color:#92400e;margin:0;">
        Hai raggiunto il massimo di {{ $tetto }} indirizzi. Per aggiungerne uno nuovo, eliminane prima uno di quelli qui sopra.
    </p>
</section>
@endif
@endsection
