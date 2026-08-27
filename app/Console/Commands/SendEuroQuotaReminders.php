<?php

namespace App\Console\Commands;

use App\Models\MarketplaceOrderPayment;
use App\Models\Order;
use App\Notifications\OrderEuroQuotaReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sollecita, UNA VOLTA SOLA, gli ordini fermi in attesa della quota in euro.
 *
 * Il caso che chiude: i KY sono già usciti dal conto di chi ha comprato, il
 * venditore aspetta di poter spedire, e l'ordine resta lì perché l'acquirente
 * non ricorda che mancava un pezzo. Nessuno dei due sa che l'altro sta
 * aspettando.
 *
 * TRE SCELTE CHE VALE LA PENA CONOSCERE
 *
 * 1. **Una volta sola.** `euro_reminder_sent_at` è la memoria. Un ordine fermo
 *    per un mese genererebbe trenta email identiche, e la trentesima non
 *    convince nessuno più della prima: fa solo finire il circuito nello spam.
 *
 * 2. **La colonna e non la tabella delle notifiche.** Dedurre "l'ho già
 *    mandata" da `notifications` funzionerebbe solo finché l'utente tiene
 *    acceso il canale `database`: chi lo spegne verrebbe sollecitato ogni
 *    notte, cioè proprio chi ha già detto che vuole meno messaggi.
 *
 * 3. **Si guarda il pagamento, non solo lo stato.** Un ordine può essere
 *    ancora `pending_payment` con il bonifico già arrivato e in attesa che il
 *    venditore lo confermi: sollecitare lì sarebbe dare del moroso a chi ha
 *    già pagato.
 */
class SendEuroQuotaReminders extends Command
{
    protected $signature = 'shop:solleciti-quota-euro
                            {--giorni=3 : Dopo quanti giorni dall\'ordine sollecitare}
                            {--dry-run : Elenca soltanto, senza mandare niente}';

    protected $description = 'Sollecita una volta sola gli ordini fermi in attesa della quota in euro';

    public function handle(): int
    {
        $giorni = max(1, (int) $this->option('giorni'));
        $prova  = (bool) $this->option('dry-run');

        $ordini = Order::query()
            ->where('status', Order::STATUS_PENDING_PAYMENT)
            ->whereNull('euro_reminder_sent_at')
            ->where('placed_at', '<=', now()->subDays($giorni))
            ->whereHas('payment', fn ($q) => $q->where('status', MarketplaceOrderPayment::STATUS_PENDING))
            ->with(['buyerUser', 'payment'])
            ->get();

        if ($ordini->isEmpty()) {
            $this->info('Nessun ordine da sollecitare.');

            return self::SUCCESS;
        }

        $mandati = 0;

        foreach ($ordini as $ordine) {
            if ($prova) {
                $this->line("[prova] {$ordine->numero} — fermo dal {$ordine->placed_at?->format('d/m/Y')}");
                continue;
            }

            // La data si scrive COMUNQUE, anche se l'invio fallisce: meglio un
            // sollecito perso che una email al giorno a chi ha una casella
            // rotta. Il fallimento resta nel log e l'ordine resta visibile in
            // "I miei ordini" con il suo avviso.
            $ordine->forceFill(['euro_reminder_sent_at' => now()])->save();

            if (! $ordine->buyerUser) {
                continue;
            }

            try {
                $ordine->buyerUser->notify(new OrderEuroQuotaReminderNotification($ordine));
                $mandati++;
            } catch (\Throwable $e) {
                Log::error('order.euro_reminder.failed', [
                    'order_id' => $ordine->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->info($prova
            ? "{$ordini->count()} ordini sarebbero stati sollecitati."
            : "Solleciti inviati: {$mandati} su {$ordini->count()} ordini fermi.");

        return self::SUCCESS;
    }
}
