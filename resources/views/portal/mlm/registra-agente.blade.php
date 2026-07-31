@extends('layouts.portal')

@section('content')
@if(session('success'))
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(22,163,74,.09);border:1px solid rgba(22,163,74,.3);color:#166534;font-size:13px;font-weight:600;">
        {{ session('success') }}
    </div>
@endif
@if($errors->any())
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.3);color:#b91c1c;font-size:13px;font-weight:600;">
        {{ $errors->first() }}
    </div>
@endif

<div class="card card-pad" style="margin-bottom:14px;width:100%;">
    <h2 style="margin:0 0 4px;font-size:18px;">Registra un nuovo agente</h2>
    <p style="margin:0 0 20px;color:var(--ink-muted);font-size:13px;line-height:1.6;max-width:760px;">
        Compila i dati della persona che vuoi nominare agente KNM nella tua struttura: le creiamo
        subito un conto e le inviamo via email le credenziali di primo accesso, insieme al
        <strong>contratto di nomina già compilato</strong> con questi dati (in allegato PDF). Per
        diventare agente attivo dovrà comunque firmarlo digitalmente con codice OTP al primo
        accesso — esattamente come nel percorso classico di richiesta/approvazione.
    </p>

    <form method="POST" action="{{ route('portal.mlm.agent-create.store') }}">
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px 20px;margin-bottom:20px;width:100%;">
            <div class="field">
                <label for="name" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Nome e cognome *</label>
                <input id="name" name="name" type="text" required maxlength="120"
                    value="{{ old('name') }}" placeholder="Mario Rossi"
                    style="width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;">
            </div>
            <div class="field">
                <label for="email" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Email *</label>
                <input id="email" name="email" type="email" required maxlength="190"
                    value="{{ old('email') }}" placeholder="mario@esempio.it"
                    style="width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;">
            </div>
            <div class="field">
                <label for="phone" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Telefono</label>
                <input id="phone" name="phone" type="tel" maxlength="30"
                    value="{{ old('phone') }}" placeholder="+39 333 0000000"
                    style="width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;">
            </div>
            <div class="field">
                <label for="sponsor_id" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Sotto quale agente registrarlo</label>
                <select id="sponsor_id" name="sponsor_id"
                    style="width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;background:var(--surface);">
                    @foreach($sponsorOptions as $option)
                        <option value="{{ $option->id }}" {{ (string) old('sponsor_id', $agent->id) === (string) $option->id ? 'selected' : '' }}>
                            {{ $option->id === $agent->id ? $option->name . ' (tu — 1° livello)' : $option->name . ' — ' . $option->email }}
                        </option>
                    @endforeach
                </select>
                <p style="margin:6px 0 0;color:var(--ink-muted);font-size:11.5px;line-height:1.5;">
                    Di default il nuovo agente viene registrato subito sotto di te. Puoi invece scegliere un
                    agente della tua struttura (a qualsiasi livello) per registrarlo sotto di lui.
                </p>
            </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--line);margin:4px 0 20px;">

        <h3 style="margin:0 0 4px;font-size:14px;">Dati per il contratto di nomina</h3>
        <p style="margin:0 0 16px;color:var(--ink-muted);font-size:12.5px;line-height:1.5;max-width:760px;">
            Obbligatori per compilare il "modulo di adesione" (Condizioni Generali per l'Incaricato
            di Vendita, ex art. 19 D. Lgs. 114/1998): il nuovo agente deve avere almeno 18 anni ed
            essere residente in Italia.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px 20px;margin-bottom:20px;width:100%;">
            <div class="field">
                <label for="fiscal_code" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Codice fiscale *</label>
                <input id="fiscal_code" name="fiscal_code" type="text" required maxlength="16" minlength="16"
                    style="text-transform:uppercase;width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;"
                    value="{{ old('fiscal_code') }}" placeholder="RSSMRA85M01H501Z">
            </div>
            <div class="field">
                <label for="birth_date" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Data di nascita *</label>
                <input id="birth_date" name="birth_date" type="date" required
                    value="{{ old('birth_date') }}"
                    style="width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;">
            </div>
            <div class="field">
                <label for="birth_place" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Luogo di nascita *</label>
                <input id="birth_place" name="birth_place" type="text" required maxlength="100"
                    value="{{ old('birth_place') }}" placeholder="Roma"
                    style="width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;">
            </div>
            <div class="field" style="grid-column:span 2;">
                <label for="residence_address" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Indirizzo di residenza *</label>
                <input id="residence_address" name="residence_address" type="text" required maxlength="190"
                    value="{{ old('residence_address') }}" placeholder="Via Roma, 10"
                    style="width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;">
            </div>
            <div class="field">
                <label for="residence_zip" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">CAP *</label>
                <input id="residence_zip" name="residence_zip" type="text" required maxlength="10"
                    value="{{ old('residence_zip') }}" placeholder="00100"
                    style="width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;">
            </div>
            <div class="field">
                <label for="residence_city" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Comune *</label>
                <input id="residence_city" name="residence_city" type="text" required maxlength="100"
                    value="{{ old('residence_city') }}" placeholder="Roma"
                    style="width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;">
            </div>
            <div class="field">
                <label for="residence_province" style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Provincia *</label>
                <input id="residence_province" name="residence_province" type="text" required maxlength="2" minlength="2"
                    style="text-transform:uppercase;width:100%;border-radius:10px;border:1px solid var(--line);padding:11px 12px;font-size:13px;"
                    value="{{ old('residence_province') }}" placeholder="RM">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Registra agente e invia contratto</button>
    </form>
</div>
@endsection
