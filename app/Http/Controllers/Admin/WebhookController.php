<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebhookController extends Controller
{
    /** GET /admin/webhooks/deliveries */
    public function webhookDeliveries(Request $request): View
    {
        $query = \App\Models\WebhookDelivery::with('webhook.company')->latest();

        if ($webhookId = $request->input('webhook_id')) {
            $query->where('webhook_id', $webhookId);
        }
        if ($event = $request->input('event')) {
            $query->where('event', $event);
        }
        if ($request->input('failed_only')) {
            $query->where('success', false);
        }

        $deliveries = $query->paginate(50)->withQueryString();
        $webhooks   = \App\Models\Webhook::with('company')->orderBy('id')->get();
        $events     = \App\Models\WebhookDelivery::distinct()->pluck('event')->sort()->values();

        return view('admin.webhook-deliveries', compact('deliveries', 'webhooks', 'events'));
    }

    /** POST /admin/webhooks/deliveries/{delivery}/retry */
    public function retryWebhook(Request $request, \App\Models\WebhookDelivery $delivery): RedirectResponse
    {
        $webhook = $delivery->webhook;
        abort_unless($webhook !== null, 404);

        \App\Jobs\SendWebhookJob::dispatch($webhook, $delivery->event, $delivery->payload ?? []);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.webhook.retry',
            'auditable_type' => \App\Models\WebhookDelivery::class,
            'auditable_id'   => $delivery->id,
            'context'        => ['event' => $delivery->event, 'webhook_id' => $webhook->id],
        ]);

        return back()->with('success', 'Retry inviato alla coda.');
    }
}
