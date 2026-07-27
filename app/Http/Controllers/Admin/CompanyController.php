<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\NettingProposal;
use App\Models\PaymentPlan;
use App\Models\Plan;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Webhook;
use App\Services\GeocodingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CompanyController extends Controller
{
    use AuthorizesBackoffice;

    public function companies(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $filters = $this->companyDirectoryFilters($request);
        [$companies, $stats, $sectorOptions] = $this->buildAdminCompanyList($filters);

        return view('admin.companies', [
            'pageTitle'    => 'Aziende del circuito',
            'companies'    => $companies,
            'stats'        => $stats,
            'filters'      => $filters,
            'sectorOptions'=> $sectorOptions,
            'planOptions'  => Plan::orderBy('display_order')->get(['id', 'name']),
            'activeNav'    => 'companies',
        ]);
    }

    public function showCompany(Request $request, Company $company): View
    {
        $this->authorizeBackoffice($request->user());

        $company->load(['broker', 'accounts.creditLimits', 'users', 'kycDocuments', 'plan']);

        $brokerUsers = User::query()
            ->where(function ($q) {
                $q->where('role', 'broker')
                  ->orWhere('is_super_admin', true);
            })
            ->orderBy('name')
            ->get();

        $account = $company->accounts->whereNull('parent_account_id')->where('status', 'active')->first();

        $staticNfcUrl = $account ? route('nfc.static.pay', $account->account_number) : null;

        $recentTransfers = $account
            ? Transfer::query()
                ->excludeLedgerCorrections()
                ->with(['fromAccount.company', 'toAccount.company', 'initiator'])
                ->where(fn ($q) => $q->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id))
                ->where('status', 'booked')
                ->latest('booked_at')
                ->take(20)
                ->get()
            : collect();

        // Integrazione e-commerce: token API e webhook dell'azienda,
        // gestibili dall'admin per configurare i plugin dei clienti.
        $apiTokens = ApiToken::where('company_id', $company->id)->with('creator')->latest()->get();
        $webhooks  = Webhook::where('company_id', $company->id)->withCount('deliveries')->latest()->get();

        // Richieste di collegamento inviate dai plugin col solo numero di
        // conto (pairing): l'admin le approva o rifiuta da questa pagina.
        $ecommercePairings = \App\Models\EcommercePairing::where('company_id', $company->id)
            ->latest()
            ->limit(20)
            ->get();

        // Metodi di pagamento EUR (Stripe/PayPal/Bonifico) configurabili
        // dall'admin per conto dell'azienda — vedi Admin\CompanyPaymentGatewayController.
        $paymentGateways = \App\Models\PaymentGateway::where('company_id', $company->id)
            ->get()
            ->keyBy('provider');

        // Storico pagamenti upgrade piano (Stripe/PayPal/bonifico/KY) dell'azienda.
        $planPayments = \App\Models\PlanPayment::where('company_id', $company->id)
            ->with(['fromPlan', 'toPlan'])
            ->latest()
            ->limit(10)
            ->get();

        return view('admin.company-show', [
            'pageTitle'       => $company->name,
            'company'         => $company,
            'account'         => $account,
            'staticNfcUrl'    => $staticNfcUrl,
            'brokerUsers'     => $brokerUsers,
            'recentTransfers' => $recentTransfers,
            'apiTokens'       => $apiTokens,
            'webhooks'        => $webhooks,
            'ecommercePairings' => $ecommercePairings,
            'paymentGateways' => $paymentGateways,
            'planPayments'    => $planPayments,
            'plans'           => Plan::orderBy('display_order')->get(),
            'activeNav'       => 'companies',
        ]);
    }

    public function assignBroker(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'broker_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $company->update(['broker_user_id' => $validated['broker_user_id'] ?: null]);

        return back()->with('portal_success',
            $validated['broker_user_id']
                ? 'Broker assegnato correttamente a ' . $company->name . '.'
                : 'Broker rimosso da ' . $company->name . '.'
        );
    }

    /**
     * POST /admin/companies/{company}/address
     *
     * Normalmente città/indirizzo li inserisce l'azienda dal proprio profilo
     * (portal.profile.update), ma alcune aziende non lo fanno mai da sole:
     * l'admin deve poterli impostare per loro conto, altrimenti restano
     * escluse dalla vista Mappa della directory /aziende. Stessa logica di
     * geocoding del form self-service (vedi GeocodingService::syncCompanyCoordinates).
     */
    public function updateAddress(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'city'    => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $company->fill($validated);

        $geocodeWarning = app(GeocodingService::class)->syncCompanyCoordinates($company);

        $company->save();

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.company.address_updated',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => [
                'city'      => $company->city,
                'address'   => $company->address,
                'geocoded'  => $company->hasCoordinates(),
            ],
        ]);

        return back()->with('portal_success',
            'Indirizzo di ' . $company->name . ' aggiornato.' . ($geocodeWarning ? ' ' . $geocodeWarning : '')
        );
    }

    // ── Sospensione azienda ───────────────────────────────────────────────────

    /** POST /admin/companies/{company}/suspend */
    public function suspendCompany(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'suspension_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $company->update([
            'suspended_at'       => now(),
            'suspension_reason'  => $data['suspension_reason'] ?? null,
        ]);

        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event'        => 'admin.company.suspend',
            'auditable_type' => Company::class,
            'auditable_id'  => $company->id,
            'context'       => ['reason' => $data['suspension_reason'] ?? null],
        ]);

        return redirect()->route('admin.company.show', $company)
            ->with('success', 'Azienda sospesa.');
    }

    /** POST /admin/companies/{company}/unsuspend */
    public function unsuspendCompany(Request $request, Company $company): RedirectResponse
    {
        $company->update([
            'suspended_at'      => null,
            'suspension_reason' => null,
        ]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.company.unsuspend',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => [],
        ]);

        return redirect()->route('admin.company.show', $company)
            ->with('success', 'Sospensione rimossa. Azienda riattivata.');
    }

    /** POST /admin/companies/{company}/activate */
    public function activateCompany(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $company->update([
            'status'     => 'active',
            'approved_at'=> $company->approved_at ?? now(),
        ]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.company.activate',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => [],
        ]);

        return back()->with('portal_success', 'Azienda ' . $company->name . ' attivata nel circuito.');
    }

    /**
     * POST /admin/companies/bulk
     * Azione in blocco su piu' aziende: attiva / disattiva / sospendi / cambia piano.
     * Target: aziende selezionate (scope=selected) o tutte quelle che rispettano
     * i filtri correnti (scope=all_filtered).
     */
    public function bulkAction(Request $request): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validPlanIds = Plan::pluck('id')->all();

        $validated = $request->validate([
            'action'        => ['required', 'in:activate,deactivate,suspend,plan'],
            'scope'         => ['required', 'in:selected,all_filtered'],
            'company_ids'   => ['array'],
            'company_ids.*' => ['integer'],
            'plan'          => ['nullable'],
        ]);

        $action = $validated['action'];

        // Query di base: aziende selezionate oppure tutte quelle filtrate.
        if ($validated['scope'] === 'all_filtered') {
            $query = $this->companyDirectoryQuery($this->companyDirectoryFilters($request));
        } else {
            $ids = $validated['company_ids'] ?? [];
            if ($ids === []) {
                return back()->with('portal_error', 'Nessuna azienda selezionata.');
            }
            $query = Company::query()->whereKey($ids);
        }

        // Per ogni azione: sottoinsieme rilevante, payload di update, evento audit e messaggi.
        if ($action === 'plan') {
            $planRaw = $validated['plan'] ?? null;
            if ($planRaw === null || $planRaw === '') {
                return back()->with('portal_error', 'Seleziona un piano da applicare.');
            }
            $planId = $planRaw === 'none' ? null : (int) $planRaw;
            if ($planId !== null && ! in_array($planId, $validPlanIds, true)) {
                return back()->with('portal_error', 'Piano non valido.');
            }
            $planName = $planId !== null ? Plan::find($planId)?->name : null;

            $query->where(fn ($q) => $planId === null
                ? $q->whereNotNull('plan_id')
                : $q->where('plan_id', '!=', $planId)->orWhereNull('plan_id'));

            $payload  = ['plan_id' => $planId];
            $event    = 'admin.company.plan';
            $auditCtx = ['bulk' => true, 'plan_id' => $planId];
            $emptyMsg = 'Nessuna azienda da aggiornare: avevano già questo piano.';
            $doneMsg  = fn (int $n) => $planId === null
                ? ($n === 1 ? 'Piano rimosso da 1 azienda.' : "Piano rimosso da {$n} aziende.")
                : ($n === 1
                    ? '1 azienda aggiornata al piano ' . $planName . '.'
                    : "{$n} aziende aggiornate al piano " . $planName . '.');
        } elseif ($action === 'activate') {
            $query->where(fn ($q) => $q->where('status', '!=', 'active')->orWhereNotNull('suspended_at'));
            $payload  = ['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null];
            $event    = 'admin.company.activate';
            $auditCtx = ['bulk' => true];
            $emptyMsg = 'Nessuna azienda da attivare: erano già tutte attive.';
            $doneMsg  = fn (int $n) => $n === 1 ? '1 azienda attivata nel circuito.' : "{$n} aziende attivate nel circuito.";
        } elseif ($action === 'suspend') {
            $query->whereNull('suspended_at');
            $payload  = ['suspended_at' => now(), 'suspension_reason' => null];
            $event    = 'admin.company.suspend';
            $auditCtx = ['bulk' => true];
            $emptyMsg = 'Nessuna azienda da sospendere: erano già tutte sospese.';
            $doneMsg  = fn (int $n) => $n === 1 ? '1 azienda sospesa.' : "{$n} aziende sospese.";
        } else { // deactivate
            $query->where('status', '!=', 'pending');
            $payload  = ['status' => 'pending'];
            $event    = 'admin.company.deactivate';
            $auditCtx = ['bulk' => true];
            $emptyMsg = 'Nessuna azienda da disattivare.';
            $doneMsg  = fn (int $n) => $n === 1 ? '1 azienda disattivata.' : "{$n} aziende disattivate.";
        }

        $companies = $query->get();

        if ($companies->isEmpty()) {
            return back()->with('portal_success', $emptyMsg);
        }

        $actorId = $request->user()->id;

        DB::transaction(function () use ($companies, $payload, $event, $auditCtx, $actorId): void {
            foreach ($companies as $company) {
                $data = $payload;
                // Preserva approved_at alla prima attivazione.
                if (($data['status'] ?? null) === 'active') {
                    $data['approved_at'] = $company->approved_at ?? now();
                }
                $company->update($data);

                AuditLog::create([
                    'actor_user_id'  => $actorId,
                    'event'          => $event,
                    'auditable_type' => Company::class,
                    'auditable_id'   => $company->id,
                    'context'        => $auditCtx,
                ]);
            }
        });

        return back()->with('portal_success', $doneMsg($companies->count()));
    }

    /** POST /admin/companies/{company}/deactivate */
    public function deactivateCompany(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $company->update(['status' => 'pending']);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.company.deactivate',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => [],
        ]);

        return back()->with('portal_success', 'Azienda ' . $company->name . ' disattivata.');
    }

    /** POST /admin/companies/{company}/ky-percentage */
    public function updateKyPercentage(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'accepted_ky_percentage' => ['nullable', 'integer', \Illuminate\Validation\Rule::in(Company::ACCEPTED_KY_PERCENTAGES)],
        ]);

        $value = $validated['accepted_ky_percentage'] ?? null;
        $company->update(['accepted_ky_percentage' => $value !== null ? (int) $value : null]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.company.ky_percentage_updated',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => ['accepted_ky_percentage' => $value],
        ]);

        return back()->with('portal_success', $value !== null
            ? 'Percentuale Kmoney di ' . $company->name . ' impostata al ' . $value . '%.'
            : 'Percentuale Kmoney di ' . $company->name . ' rimossa (non dichiarata).');
    }

    /** POST /admin/companies/{company}/plan */
    public function updatePlan(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
        ]);

        $company->update(['plan_id' => $validated['plan_id'] ?: null]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.company.plan_updated',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => ['plan_id' => $validated['plan_id'] ?? null],
        ]);

        return back()->with('portal_success', 'Piano abbonamento aggiornato.');
    }

    // ── Annullamento admin piano rateale ──────────────────────────────────────

    /** POST /admin/payment-plans/{plan}/cancel */
    public function cancelPaymentPlan(Request $request, PaymentPlan $plan): RedirectResponse
    {
        abort_unless(in_array($plan->status, ['pending_approval', 'active'], true), 422, 'Piano non annullabile in questo stato.');

        // Cancella le rate pendenti
        $plan->installments()->where('status', 'pending')->update(['status' => 'cancelled']);
        $plan->update(['status' => 'cancelled']);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.payment_plan.cancel',
            'auditable_type' => PaymentPlan::class,
            'auditable_id'   => $plan->id,
            'context'        => ['reason' => 'Annullamento forzato admin'],
        ]);

        return back()->with('success', 'Piano rateale annullato.');
    }

    // ---- Annullamento admin proposta netting ----------------------------------------

    /** POST /admin/netting/{proposal}/cancel */
    public function cancelNettingProposal(Request $request, NettingProposal $proposal): RedirectResponse
    {
        abort_unless($proposal->status === 'pending', 422, "La proposta non è più in stato pending.");

        $proposal->update(['status' => 'rejected', 'actioned_by' => $request->user()->id, 'actioned_at' => now()]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.netting.cancel',
            'auditable_type' => NettingProposal::class,
            'auditable_id'   => $proposal->id,
            'context'        => ['reason' => 'Annullamento forzato admin'],
        ]);

        return back()->with('success', 'Proposta netting annullata.');
    }

    // ── Helper directory aziende ──────────────────────────────────────────────

    private function companyDirectoryFilters(Request $request): array
    {
        $status = trim((string) $request->query('status', ''));
        $kycStatus = trim((string) $request->query('kyc_status', ''));
        $plan = trim((string) $request->query('plan', ''));

        return [
            'q'          => trim((string) $request->query('q', '')),
            'sector'     => trim((string) $request->query('sector', '')),
            'status'     => in_array($status, ['active', 'pending', 'suspended'], true) ? $status : '',
            'kyc_status' => in_array($kycStatus, ['approved', 'pending', 'under_review', 'rejected'], true) ? $kycStatus : '',
            'plan'       => ctype_digit($plan) ? $plan : '',
        ];
    }

    /**
     * Query aziende con i filtri della directory applicati (senza ordinamento/paginazione).
     * Riutilizzata sia dalla lista sia dall'attivazione in blocco "tutte le filtrate".
     */
    private function companyDirectoryQuery(array $filters): Builder
    {
        return Company::query()
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $s = $filters['q'];
                $query->where(fn ($q) =>
                    $q->where('name', 'like', "%{$s}%")
                      ->orWhere('email', 'like', "%{$s}%")
                      ->orWhere('vat_number', 'like', "%{$s}%")
                      ->orWhere('sector', 'like', "%{$s}%")
                );
            })
            ->when($filters['sector'] !== '', fn ($q) => $q->where('sector', $filters['sector']))
            // Stato allineato ai badge della vista:
            //  - Attiva  : status = 'active' e non sospesa
            //  - Sospesa : suspended_at valorizzato (qualsiasi status)
            //  - Non attiva: tutto il resto (status != 'active' e non sospesa)
            ->when($filters['status'] === 'active', fn ($q) => $q->where('status', 'active')->whereNull('suspended_at'))
            ->when($filters['status'] === 'pending', fn ($q) => $q->where('status', '!=', 'active')->whereNull('suspended_at'))
            ->when($filters['status'] === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when($filters['kyc_status'] !== '', fn ($q) => $q->where('kyc_status', $filters['kyc_status']))
            ->when($filters['plan'] !== '', fn ($q) => $q->where('plan_id', (int) $filters['plan']));
    }

    private function buildAdminCompanyList(array $filters): array
    {
        $sectorOptions = Company::query()
            ->selectRaw('sector')
            ->whereNotNull('sector')
            ->where('sector', '!=', '')
            ->distinct()
            ->orderBy('sector')
            ->pluck('sector');

        $companies = $this->companyDirectoryQuery($filters)
            ->with('plan')
            ->withCount(['users', 'listings', 'announcements'])
            ->with(['accounts' => fn ($q) => $q->whereNull('parent_account_id')
                ->where('is_system_account', false)
                ->where('owner_type', 'company')
                ->where('status', 'active')
                ->select('id', 'company_id', 'uuid', 'available_balance', 'max_balance', 'status')])
            // % Kmoney effettiva mostrata in colonna (vedi Company::computeEffectiveKyPercentage):
            // migliore % (>=25) tra i prodotti attivi non scaduti, stesso calcolo del badge nella
            // directory pubblica (vedi PortalController::buildCompanyDirectoryData).
            ->withMax(['listings as best_listing_ky_pct' => function ($q): void {
                $q->where('status', 'active')
                  ->where(function ($scope): void {
                      $scope->whereNull('expires_at')->orWhere('expires_at', '>', now());
                  })
                  ->where('ky_percentage', '>=', 25);
            }], 'ky_percentage')
            // Ordina per piano (subquery su plans.display_order, cosi' funziona anche
            // con piani creati liberamente dall'admin, senza CASE hardcoded).
            ->orderByRaw('COALESCE((SELECT display_order FROM plans WHERE plans.id = companies.plan_id), 999) ASC')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->paginate(80)
            ->withQueryString();

        $stats = [
            'total'    => Company::count(),
            'active'   => Company::where('status', 'active')->whereNull('suspended_at')->count(),
            'verified' => Company::where('kyc_status', 'approved')->count(),
            'plans'    => Company::whereNotNull('plan_id')->count(),
        ];

        return [$companies, $stats, $sectorOptions];
    }
}
