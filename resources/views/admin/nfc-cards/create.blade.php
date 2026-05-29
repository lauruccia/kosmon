@extends('layouts.portal')

@section('content')
<div class="stack">

    <div>
        <a href="{{ route('admin.nfc-cards.index') }}" style="font-size:13px;color:var(--ink-muted);">&#8592; Torna alle card</a>
        <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:8px 0 0;">Emetti nuova Card NFC</h1>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

        <section class="card card-pad">
            <form method="POST" action="{{ route('admin.nfc-cards.store') }}" class="stack">
                @csrf

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                        Cliente *
                    </label>
                    <select name="company_id" required
                            style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);">
                        <option value="">Seleziona cliente...</option>
                        @foreach($companies as $co)
                            <option value="{{ $co->id }}" @selected(old('company_id') == $co->id)>{{ $co->name }}</option>
                        @endforeach
                    </select>
                    @error('company_id')<p style="color:var(--danger);font-size:12px;margin-top:4px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                        Note interne <span style="text-transform:none;font-weight:400;">(opzionale)</span>
                    </label>
                    <textarea name="notes" rows="3" placeholder="Annotazioni sull'emissione..."
                              style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);resize:vertical;">{{ old('notes') }}</textarea>
                </div>

                <div style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:12px;color:var(--ink-muted);">
                    &#128246; Il numero seriale viene generato automaticamente nel formato <strong>KMY-YYYY-XXXXXX-C</strong>
                </div>

                <button type="submit" class="cta" style="width:100%;">
                    &#128246; Crea card e genera chip
                </button>
            </form>
        </section>

        <section class="card card-pad" style="background:var(--surface-soft);">
            <div style="font-size:13px;font-weight:700;color:var(--ink-muted);margin-bottom:14px;text-transform:uppercase;letter-spacing:.06em;">Come funziona l'emissione</div>
            <ol style="margin:0;padding-left:18px;color:var(--ink-muted);font-size:13px;line-height:1.9;">
                <li>Seleziona il cliente e crea la card</li>
                <li>Il sistema genera seriale e firma HMAC univoci</li>
                <li>Scrivi il chip NFC fisico dalla pagina dettaglio</li>
                <li>Segna la card come consegnata al cliente</li>
                <li>Il cliente la attiva impostando il PIN dall'app</li>
            </ol>
            <div style="margin-top:16px;padding:12px;background:#fff;border-radius:8px;border:1px solid var(--line);font-size:12px;color:var(--ink-muted);">
                <strong style="color:var(--ink);">Formato seriale:</strong><br>
                <code style="font-size:13px;color:var(--primary);">KMY-2026-A3F9K2-M</code><br>
                <span>KMY = prefisso · YYYY = anno · 6 char casuali · check digit</span>
            </div>
        </section>

    </div>

</div>
@endsection
