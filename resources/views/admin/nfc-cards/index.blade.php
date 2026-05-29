@extends('layouts.admin')

@section('content')
<div class="stack">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:0;">Card NFC Fisiche</h1>
            <p style="font-size:13px;color:var(--ink-muted);margin:4px 0 0;">Emissione, consegna e revoca card per i clienti.</p>
        </div>
        <a href="{{ route('admin.nfc-cards.create') }}" class="cta" style="font-size:13px;padding:9px 18px;">
            &#43; Emetti nuova card
        </a>
    </div>

    {{-- Filtri --}}
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Cerca azienda..."
               style="flex:1;min-width:180px;border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-size:13px;background:var(--surface-soft);color:var(--ink);">
        <select name="status" style="border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-size:13px;background:var(--surface-soft);color:var(--ink);">
            <option value="">Tutti gli stati</option>
            @foreach(['pending','issued','delivered','active','blocked','revoked'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="cta secondary" style="font-size:13px;padding:8px 16px;">Filtra</button>
    </form>

    <section class="card" style="overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="border-bottom:1px solid var(--line);background:var(--surface-soft);">
                    <th style="padding:10px 16px;text-align:left;font-weight:700;color:var(--ink-muted);text-transform:uppercase;font-size:11px;letter-spacing:.06em;">Seriale</th>
                    <th style="padding:10px 16px;text-align:left;font-weight:700;color:var(--ink-muted);text-transform:uppercase;font-size:11px;letter-spacing:.06em;">Cliente</th>
                    <th style="padding:10px 16px;text-align:left;font-weight:700;color:var(--ink-muted);text-transform:uppercase;font-size:11px;letter-spacing:.06em;">Stato</th>
                    <th style="padding:10px 16px;text-align:left;font-weight:700;color:var(--ink-muted);text-transform:uppercase;font-size:11px;letter-spacing:.06em;">Emessa</th>
                    <th style="padding:10px 16px;text-align:left;font-weight:700;color:var(--ink-muted);text-transform:uppercase;font-size:11px;letter-spacing:.06em;">Ultimo uso</th>
                    <th style="padding:10px 16px;text-align:right;font-weight:700;color:var(--ink-muted);text-transform:uppercase;font-size:11px;letter-spacing:.06em;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($cards as $card)
                    @php
                        $statusColors = [
                            'pending'   => ['bg'=>'#fef9c3','color'=>'#854d0e'],
                            'issued'    => ['bg'=>'#dbeafe','color'=>'#1e40af'],
                            'delivered' => ['bg'=>'#ede9fe','color'=>'#6d28d9'],
                            'active'    => ['bg'=>'#dcfce7','color'=>'#166534'],
                            'blocked'   => ['bg'=>'#fee2e2','color'=>'#991b1b'],
                            'revoked'   => ['bg'=>'#f3f4f6','color'=>'#6b7280'],
                        ];
                        $sc = $statusColors[$card->status] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                    @endphp
                    <tr style="border-bottom:1px solid var(--line);">
                        <td style="padding:12px 16px;font-weight:600;color:var(--ink);font-family:monospace;">
                            {{ $card->serial_number ?? substr($card->uuid, 0, 8) }}
                        </td>
                        <td style="padding:12px 16px;color:var(--ink);">{{ $card->company->name ?? '—' }}</td>
                        <td style="padding:12px 16px;">
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:{{ $sc['bg'] }};color:{{ $sc['color'] }};">
                                {{ strtoupper($card->status) }}
                            </span>
                        </td>
                        <td style="padding:12px 16px;color:var(--ink-muted);">{{ $card->issued_at?->format('d/m/Y') ?? '—' }}</td>
                        <td style="padding:12px 16px;color:var(--ink-muted);">{{ $card->last_used_at?->diffForHumans() ?? 'Mai' }}</td>
                        <td style="padding:12px 16px;text-align:right;">
                            <a href="{{ route('admin.nfc-cards.show', $card) }}" style="font-size:12px;font-weight:600;color:var(--primary);">Gestisci</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="padding:32px;text-align:center;color:var(--ink-muted);">Nessuna card emessa.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $cards->links() }}

</div>
@endsection
