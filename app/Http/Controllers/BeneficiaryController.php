<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBeneficiaryRequest;
use App\Models\Account;
use App\Models\SavedBeneficiary;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BeneficiaryController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->resolveAccount($request->user());

        $beneficiaries = SavedBeneficiary::where('owner_account_id', $account->id)
            ->with('beneficiaryAccount.company', 'beneficiaryAccount.ownerUser')
            ->orderBy('alias')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('portal.beneficiaries.index', [
            'pageTitle'     => 'Beneficiari salvati',
            'activeNav'     => 'beneficiari',
            'beneficiaries' => $beneficiaries,
            'account'       => $account,
        ]);
    }

    public function store(StoreBeneficiaryRequest $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        // Non può salvare sé stesso
        abort_if((int) $request->beneficiary_account_id === $account->id, 422);

        // Verifica che l'account destinatario esista e sia attivo
        $dest = Account::where('id', $request->beneficiary_account_id)
            ->where('status', 'active')
            ->firstOrFail();

        SavedBeneficiary::firstOrCreate(
            [
                'owner_account_id'       => $account->id,
                'beneficiary_account_id' => $dest->id,
            ],
            [
                'alias' => $request->alias,
                'notes' => $request->notes,
            ]
        );

        return redirect()->route('portal.beneficiaries.index')
            ->with('portal_success', 'Beneficiario salvato.');
    }

    public function update(Request $request, SavedBeneficiary $beneficiary): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());
        abort_unless($beneficiary->owner_account_id === $account->id, 403);

        $request->validate([
            'alias' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $beneficiary->update([
            'alias' => $request->alias,
            'notes' => $request->notes,
        ]);

        return redirect()->route('portal.beneficiaries.index')
            ->with('portal_success', 'Beneficiario aggiornato.');
    }

    public function destroy(Request $request, SavedBeneficiary $beneficiary): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());
        abort_unless($beneficiary->owner_account_id === $account->id, 403);

        $beneficiary->delete();

        return redirect()->route('portal.beneficiaries.index')
            ->with('portal_success', 'Beneficiario rimosso.');
    }

    /**
     * Cerca tutti i conti attivi del circuito (per form "aggiungi beneficiario").
     */
    public function search(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request->user());
        $q       = trim($request->input('q', ''));

        $query = Account::with(['company', 'ownerUser'])
            ->where('status', 'active')
            ->whereNull('parent_account_id')
            ->whereKeyNot($account->id);

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->whereHas('company', fn($c) => $c->where('name', 'like', "%{$q}%"))
                   ->orWhereHas('ownerUser', fn($c) => $c->where('name', 'like', "%{$q}%"));
            });
        }

        // Marca quelli già salvati
        $savedIds = SavedBeneficiary::where('owner_account_id', $account->id)
            ->pluck('beneficiary_account_id')
            ->flip();

        $results = $query->limit(12)->get()->map(fn($a) => [
            'id'           => $a->id,
            'display_name' => $a->company?->name ?? $a->ownerUser?->name ?? 'N/D',
            'company_name' => $a->company?->name,
            'already_saved' => $savedIds->has($a->id),
        ]);

        return response()->json($results);
    }

    /**
     * Cerca solo i beneficiari già salvati (per autocompletamento nel form pagamento).
     */
    public function searchSaved(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request->user());
        $q       = trim($request->input('q', ''));

        $query = SavedBeneficiary::where('owner_account_id', $account->id)
            ->with('beneficiaryAccount.company', 'beneficiaryAccount.ownerUser');

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('alias', 'like', "%{$q}%")
                   ->orWhereHas('beneficiaryAccount.company', fn($c) => $c->where('name', 'like', "%{$q}%"))
                   ->orWhereHas('beneficiaryAccount.ownerUser', fn($c) => $c->where('name', 'like', "%{$q}%"));
            });
        }

        $results = $query->limit(10)->get()->map(fn($b) => [
            'id'           => $b->beneficiary_account_id,
            'display_name' => $b->display_name,
            'alias'        => $b->alias,
            'company_name' => $b->beneficiaryAccount?->company?->name,
        ]);

        return response()->json($results);
    }

    // ────────────────────────────────────────────────────────────────────────

    private function resolveAccount(\App\Models\User $user): Account
    {
        if ($user->managed_account_id !== null) {
            $account = Account::with(['company', 'ownerUser'])->findOrFail($user->managed_account_id);
            return $account->parentAccount ?? $account;
        }

        if ($user->company_id !== null) {
            return Account::where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->orderBy('id')
                ->firstOrFail();
        }

        return Account::where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->firstOrFail();
    }
}
