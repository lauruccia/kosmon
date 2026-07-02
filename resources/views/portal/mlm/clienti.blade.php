@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">I miei clienti</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">
                I clienti registrati con il tuo link e collegati alla tua rete, con i loro acquisti KYCard.
            </p>
        </div>
        <span class="pill">{{ $clients->total() }} clienti</span>
    </div>
</div>

<section class="card light-card">
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Nome</th>
                <th>Registrato</th>
                <th style="text-align:right;">Acquisti totali KYCard</th>
                <th style="text-align:right;">Prezzo totale KYCard (&euro;)</th>
                <th style="text-align:right;">Importo ultima KYCard (&euro;)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($clients as $client)
                @php
                    $stat = $stats->get($client->id);
                    $last = $stat ? ($lastAmounts[$stat->last_purchase_id] ?? null) : null;
                @endphp
                <tr>
                    <td>
                        <strong style="display:block;">{{ $client->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $client->email }}</span>
                    </td>
                    <td>
                        {{ $client->created_at?->format('d/m/Y H:i') }}
                        <span style="display:block;color:var(--ink-muted);font-size:12px;">{{ $client->created_at?->diffForHumans() }}</span>
                    </td>
                    <td style="text-align:right;">{{ $stat->purchases ?? 0 }}</td>
                    <td style="text-align:right;">{{ number_format(($stat->total_eur_cents ?? 0) / 100, 2, ',', '.') }}</td>
                    <td style="text-align:right;">{{ number_format(($last ?? 0) / 100, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun cliente collegato: condividi il tuo link di invito.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<div style="margin-top:14px;">{{ $clients->links() }}</div>
@endsection
