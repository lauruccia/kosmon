@extends('emails.layout')

@section('content')
    <p class="greeting">Ciao, {{ $recipient->name }}!</p>

    @if($isPayer)
        <p>Hai programmato una serie di pagamenti ricorrenti. Di seguito trovi il piano completo con le date e gli importi.</p>
    @else
        <p><strong>{{ $fromAccount->display_name }}</strong> ha programmato una serie di pagamenti verso il tuo conto. Di seguito trovi il piano con le date previste.</p>
    @endif

    <table class="info-table">
        <tr>
            <td>Da</td>
            <td>{{ $fromAccount->display_name }} ({{ $fromAccount->account_number }})</td>
        </tr>
        <tr>
            <td>A</td>
            <td>{{ $toAccount->display_name }} ({{ $toAccount->account_number }})</td>
        </tr>
        <tr>
            <td>N. rate</td>
            <td>{{ count($payments) }}</td>
        </tr>
        <tr>
            <td>Frequenza</td>
            <td>{{ match($recurrenceType) { 'weekly' => 'Settimanale', 'biweekly' => 'Bisettimanale', default => 'Mensile' } }}</td>
        </tr>
        <tr>
            <td>Prima rata</td>
            <td>{{ \Carbon\Carbon::parse($payments[0]->scheduled_at)->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td>Ultima rata</td>
            <td>{{ \Carbon\Carbon::parse(end($payments)->scheduled_at)->format('d/m/Y') }}</td>
        </tr>
    </table>

    {{-- Piano dettagliato --}}
    <div style="margin:24px 0 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">
        Piano rate dettagliato
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
        <thead>
            <tr style="background:#f1f5f9;">
                <th style="padding:8px 10px;text-align:left;font-weight:700;color:#475569;border-bottom:2px solid #e2e8f0;">#</th>
                <th style="padding:8px 10px;text-align:left;font-weight:700;color:#475569;border-bottom:2px solid #e2e8f0;">Data scadenza</th>
                <th style="padding:8px 10px;text-align:right;font-weight:700;color:#475569;border-bottom:2px solid #e2e8f0;">Importo (KY)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payments as $i => $payment)
            <tr style="background:{{ $loop->even ? '#f8fafc' : '#ffffff' }};">
                <td style="padding:7px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;">{{ $loop->iteration }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #f1f5f9;">
                    {{ \Carbon\Carbon::parse($payment->scheduled_at)->locale('it')->isoFormat('D MMMM YYYY') }}
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;">
                    {{ ky_format($payment->amount) }} KY
                </td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background:#f1f5f9;">
                <td colspan="2" style="padding:8px 10px;font-weight:700;color:#334155;">Totale</td>
                <td style="padding:8px 10px;text-align:right;font-weight:700;color:#334155;">
                    {{ ky_format(collect($payments)->sum('amount')) }} KY
                </td>
            </tr>
        </tfoot>
    </table>

    @if($isPayer)
        <div class="alert">
            I pagamenti verranno eseguiti automaticamente alle date indicate. Assicurati di avere saldo sufficiente a ogni scadenza.
        </div>
    @else
        <div class="alert success">
            I pagamenti arriveranno automaticamente alle date indicate sul tuo conto.
        </div>
    @endif

    <div class="cta-wrap">
        <a href="{{ config('app.url') }}/pagamenti-programmati" class="cta-btn">Vedi pagamenti programmati →</a>
    </div>
@endsection
