{{--
    La pastiglia dello stato, una sola per tutte e tre le pagine ordini.

    I toni sono pochi di proposito (`Order::status_tone`): in un elenco di venti
    righe il colore serve a capire "tocca a me?" senza leggere, e sei sfumature
    diverse non si distinguono a colpo d'occhio.

    02/09/2026: i colori erano cinque terne di esadecimali scritte a mano, senza
    corrispettivo in tema scuro — la pastiglia era illeggibile su tutte e tre le
    pagine. Ora sono token, e la terna la sceglie una classe.

    @param \App\Models\Order $order
--}}
<span class="order-pill order-pill--{{ $order->status_tone }}">{{ $order->status_label }}</span>
