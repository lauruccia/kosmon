{{--
    La pastiglia dello stato, una sola per tutte e tre le pagine ordini.

    I toni sono pochi di proposito (`Order::status_tone`): in un elenco di venti
    righe il colore serve a capire "tocca a me?" senza leggere, e sei sfumature
    diverse non si distinguono a colpo d'occhio.

    @param \App\Models\Order $order
--}}
@php
    $toni = [
        'attesa'      => ['#92400e', '#fffbeb', '#fde68a'],
        'lavorazione' => ['#0c4a86', '#eff6ff', '#bfdbfe'],
        'viaggio'     => ['#5b21b6', '#f5f3ff', '#ddd6fe'],
        'concluso'    => ['#065f46', '#ecfdf5', '#a7f3d0'],
        'annullato'   => ['#991b1b', '#fef2f2', '#fecaca'],
    ];
    [$testo, $sfondo, $bordo] = $toni[$order->status_tone] ?? $toni['attesa'];
@endphp
<span style="display:inline-block;white-space:nowrap;font-size:12px;font-weight:700;
             padding:4px 10px;border-radius:999px;
             color:{{ $testo }};background:{{ $sfondo }};border:1px solid {{ $bordo }};">
    {{ $order->status_label }}
</span>
