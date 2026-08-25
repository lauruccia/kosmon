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

    // =========================================================================
    // Consegne verso le APPLICAZIONI del circuito (fase 2b)
    //
    // Registro separato da quello dei webhook aziendali, perché sono cose
    // diverse: là c'è un negoziante che si è registrato un indirizzo dal
    // portale, qui c'è un pezzo di infrastruttura configurato nel .env. È la
    // pagina che si guarda quando kshop dice "non mi è arrivato niente".
    // =========================================================================

    /** GET /admin/webhooks/applicazioni */
    public function clientDeliveries(Request $request): View
    {
        $query = \App\Models\ClientWebhookDelivery::query()->latest('id');

        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }
        if ($event = $request->input('event')) {
            $query->where('event', $event);
        }
        if ($request->input('failed_only')) {
            $query->where('success', false);
        }

        return view('admin.client-webhook-deliveries', [
            'pageTitle'   => 'Webhook applicazioni',
            'deliveries'  => $query->paginate(50)->withQueryString(),
            'clientIds'   => \App\Models\ClientWebhookDelivery::distinct()->pluck('client_id')->sort()->values(),
            'events'      => \App\Models\ClientWebhookDelivery::distinct()->pluck('event')->sort()->values(),
            'endpoints'   => app(\App\Services\ClientWebhookService::class),
        ]);
    }

    /** POST /admin/webhooks/applicazioni/{delivery}/riprova */
    public function retryClientDelivery(Request $request, \App\Models\ClientWebhookDelivery $delivery): RedirectResponse
    {
        $endpoint = app(\App\Services\ClientWebhookService::class)->endpointFor($delivery->client_id);

        if ($endpoint === null) {
            return back()->with('portal_error', 'Il canale di questa applicazione non è configurato: controlla OAUTH_..._WEBHOOK_URL nel .env.');
        }

        // Si rispedisce il corpo ESATTO di allora, non uno ricostruito adesso:
        // un retry deve consegnare lo stesso fatto, non una fotografia nuova
        // presa mezz'ora dopo.
        \App\Jobs\SendClientWebhookJob::dispatch(
            $delivery->client_id,
            $delivery->event,
            $endpoint['url'],
            $endpoint['secret'],
            (string) $delivery->body,
        );

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.client_webhook.retry',
            'auditable_type' => \App\Models\ClientWebhookDelivery::class,
            'auditable_id'   => $delivery->id,
            'context'        => ['event' => $delivery->event, 'client_id' => $delivery->client_id],
        ]);

        return back()->with('success', 'Retry inviato alla coda.');
    }
}
