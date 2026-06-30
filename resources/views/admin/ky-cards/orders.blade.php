@extends('layouts.portal')

@section('content')
<div style="width:100%;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:0;">Ordini KYCard</h1>
            <p style="margin:4px 0 0;color:var(--ink-soft);font-size:14px;">Tutti gli acquisti di ricarica KMoney</p>
        </div>
        <div style="display:flex;gap:10px;">
            <a href="{{ route('admin.ky-cards.pending-transfers') }}" class="btn btn-secondary" style="font-size:13px;">
                &#127968; Bonifici in attesa
                @if(($stats['pending'] ?? 0) > 0)
                    <span style="background:#dc2626;color:#fff;border-radius:20px;padding:1px 7px;font-size:11px;margin-left:2px;">{{ $stats['pending'] }}</span>
                @endif
            </a>
            <a href="{{ route('admin.ky-cards.index') }}" class="btn btn-secondary" style="font-size:13px;">Gestione card</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
    @endif

    {{-- KPI --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px;">
        <div class="card" style="padding:16px;text-align:center;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin-bottom:6px;">Totale ordini</div>
            <div style="font-size:26px;font-weight:800;color:var(--ink);">{{ $stats['total'] }}</div>
        </div>
        <div class="card" style="padding:16px;text-align:center;border-top:3px solid #16a34a;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin-bottom:6px;">Completati</div>
            <div style="font-size:26px;font-weight:800;color:#16a34a;">{{ $stats['completed'] }}</div>
        </div>
        <div class="card" style="padding:16px;text-align:center;border-top:3px solid #1d4ed8;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin-bottom:6px;">KY accreditati</div>
            <div style="font-size:26px;font-weight:800;color:#1d4ed8;">{{ ky_format($stats['ky_total']) }}</div>
        </div>
        <div class="card" style="padding:16px;text-align:center;border-top:3px solid #7c3aed;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin-bottom:6px;">Incassato</div>
            <div style="font-size:26px;font-weight:800;color:#7c3aed;">{{ number_format($stats['eur_total']/100,2,',','.') }} &euro;</div>
        </div>
    </div>

    {{-- Filtri --}}
    <form method="GET" style="display:flex;gap:10px;margin-bottom:20px;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label class="form-label">Stato</label>
            <select name="status" class="form-control" style="min-width:160px;">
                <option value="">Tutti gli stati</option>
                <option value="pending" {{ request('status')==='pending' ? 'selected' : '' }}>In attesa</option>
                <option value="pending_bank_transfer" {{ request('status')==='pending_bank_transfer' ? 'selected' : '' }}>Attende bonifico</option>
                <option value="completed" {{ request('status')==='completed' ? 'selected' : '' }}>Completato</option>
                <option value="failed" {{ request('status')==='failed' ? 'selected' : '' }}>Fallito</option>
                <option value="refunded" {{ request('status')==='refunded' ? 'selected' : '' }}>Rimborsato</option>
            </select>
        </div>
        <div>
            <label class="form-label">Metodo</label>
            <select name="method" class="form-control" style="min-width:140px;">
                <option value="">Tutti i metodi</option>
                <option value="stripe" {{ request('method')==='stripe' ? 'selected' : '' }}>Carta (Stripe)</option>
                <option value="paypal" {{ request('method')==='paypal' ? 'selected' : '' }}>PayPal</option>
                <option value="bank_transfer" {{ request('method')==='bank_transfer' ? 'selected' : '' }}>Bonifico</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filtra</button>
        <a href="{{ route('admin.ky-cards.orders') }}" class="btn btn-secondary">Reset</a>
    </form>

    {{-- Tabella ordini --}}
    @if($orders->isEmpty())
        <div class="card" style="padding:40px;text-align:center;color:var(--ink-muted);">Nessun ordine trovato.</div>
    @else
    <div class="card" style="padding:0;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">Data</th>
                    <th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">Cliente</th>
                    <th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">Card</th>
                    <th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">Metodo</th>
                    <th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">Pagato</th>
                    <th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">KY</th>
                    <th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">Stato</th>
                    <th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">Azioni</th>
                </tr>
            </thead>
            <tbody>
            @foreach($orders as $order)
            <tr style="border-bottom:1px solid #f1f5f9;{{ $loop->even ? 'background:#fafafa;' : '' }}">
                <td style="padding:12px 16px;color:var(--ink-muted);white-space:nowrap;">
                    {{ $order->created_at->format('d/m/Y') }}<br>
                    <span style="font-size:11px;">{{ $order->created_at->format('H:i') }}</span>
                </td>
                <td style="padding:12px 16px;">
                    <div style="font-weight:600;">{{ $order->user->name ?? '—' }}</div>
                    <div style="font-size:12px;color:var(--ink-muted);">{{ $order->account->display_name ?? '—' }}</div>
                </td>
                <td style="padding:12px 16px;">
                    <div style="font-weight:600;">{{ $order->kyCard->name ?? '—' }}</div>
                    @if($order->payment_method === 'bank_transfer')
                        <div style="font-size:11px;color:#7c3aed;font-family:monospace;">{{ $order->bank_transfer_reference }}</div>
                    @endif
                </td>
                <td style="padding:12px 16px;text-align:center;">
                    @if($order->payment_method === 'stripe')
                        <span style="font-size:12px;background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:20px;font-weight:600;">&#128179; Carta</span>
                    @elseif($order->payment_method === 'paypal')
                        <span style="font-size:12px;background:#e8f4fd;color:#003087;padding:2px 8px;border-radius:20px;font-weight:600;">PayPal</span>
                    @else
                        <span style="font-size:12px;background:#f5f3ff;color:#7c3aed;padding:2px 8px;border-radius:20px;font-weight:600;">&#127968; Bonifico</span>
                    @endif
                </td>
                <td style="padding:12px 16px;text-align:right;font-weight:600;">{{ number_format($order->price_eur,2,',','.') }} &euro;</td>
                <td style="padding:12px 16px;text-align:right;font-weight:700;color:#1d4ed8;">+{{ ky_format($order->ky_amount) }} KY</td>
                <td style="padding:12px 16px;text-align:center;">
                    @if($order->isCompleted())
                        <span style="font-size:12px;background:#f0fdf4;color:#166534;padding:3px 10px;border-radius:20px;font-weight:700;">&#10003; Completato</span>
                    @elseif($order->isPendingBankTransfer())
                        <span style="font-size:12px;background:#fffbeb;color:#92400e;padding:3px 10px;border-radius:20px;font-weight:700;">&#9203; Attende bonifico</span>
                    @elseif($order->isPending())
                        <span style="font-size:12px;background:#eff6ff;color:#1d4ed8;padding:3px 10px;border-radius:20px;font-weight:700;">&#8987; In elaborazione</span>
                    @elseif($order->isFailed())
                        <span style="font-size:12px;background:#fef2f2;color:#991b1b;padding:3px 10px;border-radius:20px;font-weight:700;">&#10007; Fallito</span>
                    @else
                        <span style="font-size:12px;background:#f1f5f9;color:var(--ink-muted);padding:3px 10px;border-radius:20px;">{{ $order->status }}</span>
                    @endif
                </td>
                <td style="padding:12px 16px;text-align:right;">
                    @if($order->isFailed())
                        <form method="POST" action="{{ route('admin.ky-cards.retry-credit', $order) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary"
                                    onclick="return confirm('Riprocessare questo accredito?')">
                                Riprova
                            </button>
                        </form>
                    @elseif($order->isPendingBankTransfer())
                        <a href="{{ route('admin.ky-cards.pending-transfers') }}" class="btn btn-sm btn-secondary">Gestisci</a>
                    @elseif($order->isCompleted() && $order->transfer_id)
                        <a href="{{ route('portal.movements') }}" class="btn btn-sm btn-secondary" style="font-size:11px;">Movm.</a>
                    @endif
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div style="margin-top:16px;">
        {{ $orders->links() }}
    </div>
    @endif

</div>
@endsection
