@extends('layouts.portal')

@section('content')
<div style="max-width:560px;">
    <div class="stack">

        <div>
            <a href="{{ route('admin.nfc-cards.index') }}" style="font-size:13px;color:var(--ink-muted);">&#8592; Torna alle card</a>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:8px 0 0;">Emetti nuova Card NFC</h1>
        </div>

        <section class="card card-pad">
            <form method="POST" action="{{ route('admin.nfc-cards.store') }}" class="stack">
                @csrf

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                        Cliente (azienda) *
                    </label>
                    <select name="company_id" required
                            style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);">
                        <option value="">Seleziona azienda...</option>
                        @foreach($companies as $co)
                            <option value="{{ $co->id }}" @selected(old('company_id') == $co->id)>{{ $co->name }}</option>
                        @endforeach
                    </select>
                    @error('company_id')<p style="color:var(--danger);font-size:12px;margin-top:4px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                        Numero seriale card <span style="text-transform:none;font-weight:400;">(opzionale)</span>
                    </label>
                    <input type="text" name="serial_number" value="{{ old('serial_number') }}"
                           placeholder="es. KMY-2026-001"
                           style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);">
                    @error('serial_number')<p style="color:var(--danger);font-size:12px;margin-top:4px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                        Note interne <span style="text-transform:none;font-weight:400;">(opzionale)</span>
                    </label>
                    <textarea name="notes" rows="3" placeholder="Annotazioni sull'emissione..."
                              style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);resize:vertical;">{{ old('notes') }}</textarea>
                </div>

                <button type="submit" class="cta" style="width:100%;">
                    &#128246; Crea card e genera chip
                </button>
            </form>
        </section>

        <section class="card card-pad" style="background:var(--surface-soft);">
            <div style="font-size:12px;font-weight:700;color:var(--ink-muted);margin-bottom:8px;">Come funziona l'emissione</div>
            <ol style="margin:0;padding-left:18px;color:var(--ink-muted);font-size:13px;line-height:1.7;">
                <li>Crei la card — il sistema genera UUID e firma HMAC</li>
                <li>Vai al dettaglio card e scrivi il chip NFC fisico</li>
                <li>Segna la card come consegnata al cliente</li>
                <li>Il cliente la attiva dall'app impostando il PIN</li>
            </ol>
        </section>

    </div>
</div>
@endsection
