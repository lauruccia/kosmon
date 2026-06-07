<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\User;
use App\Notifications\ApiTokenRevokedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiTokenController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        [$account, $company] = $this->resolveContext($request->user());

        $tokens = ApiToken::where('company_id', $company->id)
            ->latest()
            ->get();

        return view('portal.api-tokens.index', [
            'pageTitle' => 'Token API',
            'tokens'    => $tokens,
            'activeNav' => 'api-tokens',
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $this->resolveContext($request->user());

        return view('portal.api-tokens.create', [
            'pageTitle' => 'Nuovo token API',
            'activeNav' => 'api-tokens',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$account, $company, $user] = $this->resolveContext($request->user());

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'abilities'  => ['required', 'array', 'min:1'],
            'abilities.*'=> ['in:read,write'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        [$raw, $hash, $prefix] = ApiToken::generateRaw();

        $token = ApiToken::create([
            'company_id'   => $company->id,
            'created_by'   => $user->id,
            'name'         => $data['name'],
            'token_hash'   => $hash,
            'token_prefix' => $prefix,
            'abilities'    => $data['abilities'],
            'expires_at'   => $data['expires_at'] ?? null,
        ]);

        // Salva il token in chiaro in sessione — mostrato una sola volta
        return redirect()
            ->route('portal.api-tokens.show', $token)
            ->with('new_token_plain', $raw)
            ->with('portal_success', 'Token creato. Copia il valore ora — non sarà più visibile.');
    }

    public function show(Request $request, ApiToken $apiToken): View|RedirectResponse
    {
        [, $company] = $this->resolveContext($request->user());
        abort_unless($apiToken->company_id === $company->id, 403);

        return view('portal.api-tokens.show', [
            'pageTitle'  => 'Token: ' . $apiToken->name,
            'token'      => $apiToken,
            'plainToken' => session('new_token_plain'),
            'activeNav'  => 'api-tokens',
        ]);
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        [, $company, $user] = $this->resolveContext($request->user());
        abort_unless($apiToken->company_id === $company->id, 403);

        // Clona i dati prima della delete per la notifica
        $tokenForNotification = clone $apiToken;

        $apiToken->delete();

        // Notifica all'utente che ha creato il token (o all'owner del company)
        $recipient = $tokenForNotification->creator ?? $user;
        $recipient->notify(new ApiTokenRevokedNotification($tokenForNotification, $request->ip()));

        return redirect()
            ->route('portal.api-tokens.index')
            ->with('portal_success', 'Token revocato.');
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

        return [$account, $account->company, $viewer];
    }
}
