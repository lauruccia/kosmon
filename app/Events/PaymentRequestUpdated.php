<?php

namespace App\Events;

use App\Models\PaymentRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast quando un PaymentRequest (QR o NFC) cambia stato.
 * Il merchant ascolta il canale payment-request.{token} per aggiornamenti real-time.
 */
class PaymentRequestUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PaymentRequest $paymentRequest,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('payment-request.' . $this->paymentRequest->token),
        ];
    }

    public function broadcastAs(): string
    {
        return 'status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'status'      => $this->paymentRequest->status,
            'paid_at'     => $this->paymentRequest->paid_at?->format('H:i'),
            'payer_name'  => $this->paymentRequest->fromAccount?->company?->name
                ?? $this->paymentRequest->fromAccount?->display_name,
            'seconds_left' => $this->paymentRequest->expires_at
                ? max(0, now()->diffInSeconds($this->paymentRequest->expires_at, false))
                : 0,
        ];
    }
}
