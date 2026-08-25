@extends('layouts.portal')

@section('content')
<div style="max-width:520px;margin:48px auto;">
    <section class="card card-pad">

        <div style="text-align:center;margin-bottom:26px;">
            <div style="font-size:44px;line-height:1;margin-bottom:12px;">&#x26A1;</div>
            <h2 style="font-size:20px;font-weight:800;margin:0 0 8px;">
                Paga su {{ $clientName }} con un clic
            </h2>
            <p style="font-size:14px;color:var(--text-muted);margin:0;">
                Senza tornare qui a confermare ogni acquisto.
            </p>
        </div>

        @if($errors->has('max_per_transaction'))
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:13px;color:#b91c1c;">
                {{ $errors->first('max_per_transaction') }}
            </div>
        @endif

        <form method="POST" action="{{ route('oauth.mandate.grant') }}" style="margin:0;">
            @csrf

            <label style="display:block;font-size:14px;font-weight:700;margin-bottom:8px;">
                Fino a quanto, per singolo acquisto?
            </label>

            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <input type="number" name="max_per_transaction" step="0.01"
                       min="{{ number_format($minCap / 100, 2, '.', '') }}"
                       max="{{ number_format($maxCap / 100, 2, '.', '') }}"
                       value="{{ old('max_per_transaction', number_format($defaultCap / 100, 2, '.', '')) }}"
                       class="field-input"
                       style="flex:1;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:16px;">
                <span style="font-size:15px;font-weight:700;color:var(--text-muted);">KY</span>
            </div>

            <p style="font-size:13px;color:var(--text-muted);margin:0 0 20px;line-height:1.5;">
                Sopra questa cifra l'acquisto <strong>non viene bloccato</strong>: ti verrà
                semplicemente chiesto di confermarlo qui su KMoney, come fai adesso.
            </p>

            <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:22px;">
                <div style="font-size:13px;font-weight:700;margin-bottom:8px;">Cosa stai autorizzando, in concreto</div>
                <ul style="list-style:none;padding:0;margin:0;font-size:13px;line-height:1.6;color:var(--text-muted);">
                    <li>&#x2705; Pagamenti solo verso i venditori che approvi tu, uno per volta.</li>
                    @if($sellerAccount)
                        <li style="padding-left:20px;">Il primo è <strong>{{ $sellerAccount->company?->name ?? $sellerAccount->uuid }}</strong>.</li>
                    @endif
                    <li>&#x2705; Ogni addebito ti arriva come notifica, subito.</li>
                    <li>&#x2705; Puoi revocare quando vuoi, con un clic, da "App collegate".</li>
                    <li>&#x2705; Scade da sola fra {{ $months }} mesi.</li>
                    <li>&#x274C; {{ $clientName }} non vede il tuo saldo e non può pagare nessun altro.</li>
                </ul>
            </div>

            <div style="font-size:13px;color:var(--text-muted);margin-bottom:18px;">
                Conto addebitato: <strong>{{ $account->uuid }}</strong>
            </div>

            <button type="submit" class="cta" style="width:100%;">
                Autorizza fino a {{ old('max_per_transaction', number_format($defaultCap / 100, 2, ',', '.')) }} KY per acquisto
            </button>
        </form>

        <form method="POST" action="{{ route('oauth.mandate.deny') }}" style="margin:10px 0 0;">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-outline" style="width:100%;background:none;">
                No, preferisco confermare ogni volta
            </button>
        </form>

    </section>
</div>
@endsection
