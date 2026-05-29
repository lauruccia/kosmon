<?php

namespace App\Http\Controllers;

use App\Jobs\SendWebhookJob;
use App\Models\Account;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebhookController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        [$currentAccount, $company] = $this->resolveContext($request->user());

        $webhooks = Webhook::where('company_id', $company->id)
            ->withCount('deliveries')
            ->latest()
            ->get();

        return view('portal.webhooks.index', [
            'pageTitle'  => 'Webhook',
            'webhooks'   => $webhooks,
            'activeNav'  => 'webhooks',
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $this->resolveContext($request->user());

        return view('portal.webhooks.create', [
            'pageTitle'     => 'Nuovo webhook',
            'eventOptions'  => Webhook::EVENTS,
            'activeNav'     => 'webhooks',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [, $company] = $this->resolveContext($request->user());

        $data = $request->validate([
            'url'    => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:' . implode(',', array_keys(Webhook::EVENTS))],
        ]);

        $webhook = Webhook::create([
            'company_id' => $company->id,
            'url'        => $data['url'],
            'events'     => $data['events'],
        ]);

        return redirect()
            ->route('portal.webhooks.show', $webhook)
            ->with('portal_success', 'Webhook creato. Copia il segreto di firma — non sarà più visibile.');
    }

    public function show(Request $request, Webhook $webhook): View|RedirectResponse
    {
        [, $company] = $this->resolveContext($request->user());
        abort_unless($webhook->company_id === $company->id, 403);

        $deliveries = $webhook->deliveries()->latest()->limit(20)->get();

        return view('portal.webhooks.show', [
            'pageTitle'  => 'Webhook ' . parse_url($webhook->url, PHP_URL_HOST),
            'webhook'    => $webhook,
            'deliveries' => $deliveries,
            'eventLabels'=> Webhook::EVENTS,
            'activeNav'  => 'webhooks',
        ]);
    }

    public function toggle(Request $request, Webhook $webhook): RedirectResponse
    {
        [, $company] = $this->resolveContext($request->user());
        abort_unless($webhook->company_id === $company->id, 403);

        $webhook->update([
            'is_active'     => ! $webhook->is_active,
            'failure_count' => $webhook->is_active ? $webhook->failure_count : 0,
        ]);

        $label = $webhook->is_active ? 'riattivato' : 'disattivato';
        return back()->with('portal_success', 'Webhook ' . $label . '.');
    }

    public function destroy(Request $request, Webhook $webhook): RedirectResponse
    {
        [, $company] = $this->resolveContext($request->user());
        abort_unless($webhook->company_id === $company->id, 403);

        $webhook->delete();
        return redirect()->route('portal.webhooks.index')->with('portal_success', 'Webhook eliminato.');
    }

    public function test(Request $request, Webhook $webhook): RedirectResponse
    {
        [, $company] = $this->resolveContext($request->user());
        abort_unless($webhook->company_id === $company->id, 403);
        abort_unless($webhook->is_active, 422, 'Il webhook è disattivato.');

        SendWebhookJob::dispatch($webhook, 'ping', [
            'message' => 'Test webhook da KMoney',
            'company' => $company->name,
        ]);

        return back()->with('portal_success', 'Evento di test inviato. Controlla i log delle consegne tra qualche secondo.');
    }

    private function resolveContext(User $viewer): array
    {
        abort_if($viewer->canAccessBackoffice(), 403);

        $account = Account::query()
            ->with(['company'])
            ->where('company_id', $viewer->company_id ?? 0)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->firstOrFail();

        abort_if($account->company === null, 403);

        return [$account, $account->company];
    }
}
