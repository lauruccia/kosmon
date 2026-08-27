{{-- I campi di un indirizzo di spedizione. Uno solo, riusato da: rubrica
     (aggiungi e modifica) e cassa ("usa un nuovo indirizzo"). Se cambiano le
     regole di validazione, cambiano in un posto solo.
     $indirizzo puo' essere null (form di creazione); $prefissoId serve perche'
     nella stessa pagina ci sono piu' copie di questo form e gli id devono
     restare unici.

     `required`, `autocomplete` e i messaggi sotto il campo sono del 27/08/2026
     (audit 26/08, blocco 5). Non sono decorazioni:

     - senza `autocomplete` il compilatore automatico del telefono NON parte, e
       in cassa da un cellulare l'indirizzo va riscritto a mano tutto;
     - senza `required` l'unico segnale di un campo mancante era una riga in
       cima a una pagina lunga — "servono almeno nome, via, citta' e CAP" — e
       toccava indovinare QUALE mancasse;
     - il messaggio d'errore sotto il campo giusto e' dove chi compila lo sta
       cercando.

     Il server valida esattamente come prima: questo e' un aiuto a chi scrive,
     non una difesa. --}}
@php
    // La stessa riga sei volte, scritta una volta sola.
    $erroreDi = fn (string $campo) => $errors->first($campo);
@endphp
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div style="grid-column:1 / -1;">
        <label class="field-label" for="lab-{{ $prefissoId }}">Etichetta <span class="subtle" style="font-weight:400;">(facoltativa — es. Casa, Ufficio)</span></label>
        <input class="field-input" id="lab-{{ $prefissoId }}" type="text" name="label" maxlength="60"
               autocomplete="off"
               value="{{ old('label', $indirizzo?->label) }}">
        @if($erroreDi('label'))<p class="campo-errore">{{ $erroreDi('label') }}</p>@endif
    </div>
    <div style="grid-column:1 / -1;">
        <label class="field-label" for="dest-{{ $prefissoId }}">Destinatario</label>
        <input class="field-input" id="dest-{{ $prefissoId }}" type="text" name="recipient_name" maxlength="150"
               autocomplete="name" required
               value="{{ old('recipient_name', $indirizzo?->recipient_name) }}">
        @if($erroreDi('recipient_name'))<p class="campo-errore">{{ $erroreDi('recipient_name') }}</p>@endif
    </div>
    <div style="grid-column:1 / -1;">
        <label class="field-label" for="via-{{ $prefissoId }}">Indirizzo</label>
        <input class="field-input" id="via-{{ $prefissoId }}" type="text" name="address" maxlength="255"
               autocomplete="street-address" required
               placeholder="Via, numero civico, scala, interno"
               value="{{ old('address', $indirizzo?->address) }}">
        @if($erroreDi('address'))<p class="campo-errore">{{ $erroreDi('address') }}</p>@endif
    </div>
    <div>
        <label class="field-label" for="cap-{{ $prefissoId }}">CAP</label>
        {{-- inputmode numerico e NON type="number": il CAP e' una stringa —
             gli zeri iniziali contano e 00118 non e' un numero da
             incrementare — ma sul telefono deve aprire la tastiera dei
             numeri. --}}
        <input class="field-input" id="cap-{{ $prefissoId }}" type="text" name="postal_code" maxlength="12"
               autocomplete="postal-code" inputmode="numeric" required
               value="{{ old('postal_code', $indirizzo?->postal_code) }}">
        @if($erroreDi('postal_code'))<p class="campo-errore">{{ $erroreDi('postal_code') }}</p>@endif
    </div>
    <div>
        <label class="field-label" for="cit-{{ $prefissoId }}">Città</label>
        <input class="field-input" id="cit-{{ $prefissoId }}" type="text" name="city" maxlength="100"
               autocomplete="address-level2" required
               value="{{ old('city', $indirizzo?->city) }}">
        @if($erroreDi('city'))<p class="campo-errore">{{ $erroreDi('city') }}</p>@endif
    </div>
    <div>
        <label class="field-label" for="pro-{{ $prefissoId }}">Provincia <span class="subtle" style="font-weight:400;">(facoltativa)</span></label>
        <input class="field-input" id="pro-{{ $prefissoId }}" type="text" name="province" maxlength="60"
               autocomplete="address-level1"
               value="{{ old('province', $indirizzo?->province) }}">
        @if($erroreDi('province'))<p class="campo-errore">{{ $erroreDi('province') }}</p>@endif
    </div>
    <div>
        <label class="field-label" for="tel-{{ $prefissoId }}">Telefono <span class="subtle" style="font-weight:400;">(facoltativo)</span></label>
        <input class="field-input" id="tel-{{ $prefissoId }}" type="tel" name="phone" maxlength="30"
               autocomplete="tel"
               value="{{ old('phone', $indirizzo?->phone) }}">
        @if($erroreDi('phone'))<p class="campo-errore">{{ $erroreDi('phone') }}</p>@endif
    </div>
</div>
