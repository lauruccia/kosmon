{{-- I campi di un indirizzo di spedizione. Uno solo, riusato da: rubrica
     (aggiungi e modifica) e cassa ("usa un nuovo indirizzo"). Se cambiano le
     regole di validazione, cambiano in un posto solo.
     $indirizzo puo' essere null (form di creazione); $prefissoId serve perche'
     nella stessa pagina ci sono piu' copie di questo form e gli id devono
     restare unici. --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div style="grid-column:1 / -1;">
        <label class="field-label" for="lab-{{ $prefissoId }}">Etichetta <span class="subtle" style="font-weight:400;">(facoltativa — es. Casa, Ufficio)</span></label>
        <input class="field-input" id="lab-{{ $prefissoId }}" type="text" name="label" maxlength="60"
               value="{{ old('label', $indirizzo?->label) }}">
    </div>
    <div style="grid-column:1 / -1;">
        <label class="field-label" for="dest-{{ $prefissoId }}">Destinatario</label>
        <input class="field-input" id="dest-{{ $prefissoId }}" type="text" name="recipient_name" maxlength="150"
               value="{{ old('recipient_name', $indirizzo?->recipient_name) }}">
    </div>
    <div style="grid-column:1 / -1;">
        <label class="field-label" for="via-{{ $prefissoId }}">Indirizzo</label>
        <input class="field-input" id="via-{{ $prefissoId }}" type="text" name="address" maxlength="255"
               placeholder="Via, numero civico, scala, interno"
               value="{{ old('address', $indirizzo?->address) }}">
    </div>
    <div>
        <label class="field-label" for="cap-{{ $prefissoId }}">CAP</label>
        <input class="field-input" id="cap-{{ $prefissoId }}" type="text" name="postal_code" maxlength="12"
               value="{{ old('postal_code', $indirizzo?->postal_code) }}">
    </div>
    <div>
        <label class="field-label" for="cit-{{ $prefissoId }}">Città</label>
        <input class="field-input" id="cit-{{ $prefissoId }}" type="text" name="city" maxlength="100"
               value="{{ old('city', $indirizzo?->city) }}">
    </div>
    <div>
        <label class="field-label" for="pro-{{ $prefissoId }}">Provincia <span class="subtle" style="font-weight:400;">(facoltativa)</span></label>
        <input class="field-input" id="pro-{{ $prefissoId }}" type="text" name="province" maxlength="60"
               value="{{ old('province', $indirizzo?->province) }}">
    </div>
    <div>
        <label class="field-label" for="tel-{{ $prefissoId }}">Telefono <span class="subtle" style="font-weight:400;">(facoltativo)</span></label>
        <input class="field-input" id="tel-{{ $prefissoId }}" type="text" name="phone" maxlength="30"
               value="{{ old('phone', $indirizzo?->phone) }}">
    </div>
</div>
