<?php

namespace App\Http\Controllers;

use App\Mail\PaymentReceived;
use App\Mail\PaymentSent;
use App\Mail\CreditNoteIssued;
use App\Mail\RefundIssued;
use App\Mail\PaymentRequestConfirmed;
use App\Mail\PaymentRequestRejected;
use App\Mail\PaymentRequested;
use App\Models\Account;
use App\Models\Company;
use App\Models\Sector;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\CreditNoteIssuedNotification;
use App\Notifications\RefundIssuedNotification;
use App\Notifications\PaymentRequestConfirmedNotification;
use App\Notifications\PaymentRequestRejectedNotification;
use App\Models\KyCardPurchase;
use App\Models\TextPaymentRequest;
use App\Notifications\PaymentRequestedNotification;
use App\Services\TransferBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalController extends Controller
{
    public function dashboard(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser, $rootAccount] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));

        $recentTransfers = $this->accountTransfers($currentAccount)->limit(8)->get();

        $currentBalance = (int) $currentAccount->available_balance;
        $massimale = $currentAccount->massimale();
        $availableBalance = $currentAccount->saldoDisponibile();
        $commercialAvailability = $currentAccount->disponibilitaCommerciale();
        $commercialAvailabilityUsed = $currentAccount->disponibilitaCommercialeUsata();
        $commercialAvailabilityResidual = $currentAccount->disponibilitaCommercialeResidua();
        $commercialAvailabilityUsagePercentage = $currentAccount->disponibilitaCommercialePercentualeUtilizzo();
        $effectiveUserLimits = $currentUser->effectiveTransferLimits();
        $maxSingle = $effectiveUserLimits['per_movement_limit'] ?? $currentAccount->spending_limit ?? 0;

        $monthlyTrend = Cache::remember(
            "dashboard.monthly_trend.{$currentAccount->id}",
            now()->addMinutes(10),
            function () use ($currentAccount) {
                return collect(range(2, 0))->map(function (int $offset) use ($currentAccount) {
                    $month = CarbonImmutable::now()->subMonths($offset);
                    return [
                        'label'   => $month->locale('it')->translatedFormat('M'),
                        'income'  => Transfer::query()->where('to_account_id', $currentAccount->id)->where('status', 'booked')->whereYear('booked_at', $month->year)->whereMonth('booked_at', $month->month)->sum('amount'),
                        'expense' => Transfer::query()->where('from_account_id', $currentAccount->id)->where('status', 'booked')->whereYear('booked_at', $month->year)->whereMonth('booked_at', $month->month)->sum('amount'),
                    ];
                });
            }
        );

        // Richieste di pagamento in attesa che il conto corrente deve confermare o rifiutare
        $pendingIncomingRequests = Transfer::query()
            ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser'])
            ->where('from_account_id', $currentAccount->id)
            ->where('kind', 'portal_collection_request')
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        if ($this->isDelegateView($currentUser, $currentAccount)) {
            $dailySpent = Transfer::query()
                ->where('from_account_id', $currentAccount->id)
                ->where('status', 'booked')
                ->whereBetween('booked_at', [CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay()])
                ->sum('amount');

            $dailyLimit = $effectiveUserLimits['daily_transaction_limit'] ?? $currentAccount->daily_outgoing_limit;
            $remainingDailyLimit = $dailyLimit === null ? null : max(0, $dailyLimit - $dailySpent);

            return view('portal.delegate-dashboard', [
                'pageTitle' => 'Vista delegato',
                'currentAccount' => $currentAccount,
                'currentUser' => $currentUser,
                'rootAccount' => $rootAccount,
                'recentTransfers' => $recentTransfers,
                'currentBalance' => $currentBalance,
                'availableBalance' => $availableBalance,
                'massimale' => $massimale,
                'commercialAvailability' => $commercialAvailability,
                'maxSingle' => $maxSingle,
                'dailySpent' => $dailySpent,
                'remainingDailyLimit' => $remainingDailyLimit,
                'effectiveUserLimits' => $effectiveUserLimits,
                'activeNav' => 'conto',
            ]);
        }


        // 30-day KPI con confronto mese precedente — cache 5 minuti per account
        [$income30, $expense30, $incomePrev, $expensePrev, $kyCardCount, $kyCardTotalKy] = Cache::remember(
            "dashboard.kpi30.{$currentAccount->id}",
            now()->addMinutes(5),
            function () use ($currentAccount) {
                $now = CarbonImmutable::now();
                return [
                    Transfer::query()->where('to_account_id', $currentAccount->id)->where('status', 'booked')->where('booked_at', '>=', $now->subDays(30))->sum('amount'),
                    Transfer::query()->where('from_account_id', $currentAccount->id)->where('status', 'booked')->where('booked_at', '>=', $now->subDays(30))->sum('amount'),
                    Transfer::query()->where('to_account_id', $currentAccount->id)->where('status', 'booked')->whereBetween('booked_at', [$now->subDays(60), $now->subDays(30)])->sum('amount'),
                    Transfer::query()->where('from_account_id', $currentAccount->id)->where('status', 'booked')->whereBetween('booked_at', [$now->subDays(60), $now->subDays(30)])->sum('amount'),
                    KyCardPurchase::where('account_id', $currentAccount->id)->where('status', 'completed')->count(),
                    (int) KyCardPurchase::where('account_id', $currentAccount->id)->where('status', 'completed')->sum('ky_amount'),
                ];
            }
        );
        $incomeTrend  = $incomePrev  > 0 ? round(($income30  - $incomePrev)  / $incomePrev  * 100) : null;
        $expenseTrend = $expensePrev > 0 ? round(($expense30 - $expensePrev) / $expensePrev * 100) : null;

        $activeCreditLimit = $currentAccount->activeCreditLimit();

        // Limiti del conto (utente + account)
        $limitMaxBalance   = $currentAccount->max_balance;
        $limitSingleTx     = $effectiveUserLimits['per_movement_limit']        ?? null;
        $limitDaily        = $effectiveUserLimits['daily_transaction_limit']    ?? null;
        $limitMonthly      = $effectiveUserLimits['monthly_transaction_limit']  ?? null;

        return view('portal.dashboard', compact('currentAccount', 'currentUser', 'recentTransfers', 'currentBalance', 'availableBalance', 'massimale', 'commercialAvailability', 'commercialAvailabilityUsed', 'commercialAvailabilityResidual', 'commercialAvailabilityUsagePercentage', 'maxSingle', 'monthlyTrend') + [
            'rootAccount' => $rootAccount,
            'subaccounts' => $rootAccount->childAccounts()->with('managedUsers')->orderBy('id')->get(),
            'canManageSubaccounts' => $currentUser->canCreateSubaccountsFor($rootAccount),
            'pendingIncomingRequests' => $pendingIncomingRequests,
            'income30'   => $income30,
            'expense30'  => $expense30,
            'incomeTrend'  => $incomeTrend,
            'expenseTrend' => $expenseTrend,
            'kyCardCount'   => $kyCardCount,
            'kyCardTotalKy' => $kyCardTotalKy,
            'limitMaxBalance' => $limitMaxBalance,
            'limitSingleTx'   => $limitSingleTx,
            'limitDaily'      => $limitDaily,
            'limitMonthly'    => $limitMonthly,
            'pageTitle' => 'Conto KMoney',
            'activeNav' => 'conto',
        ]);
    }

    public function companies(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canViewCompaniesDirectory(), 403);

        $filters = $this->companyDirectoryFilters($request);
        // Nel portale utenti mostriamo solo aziende attive e verificate KYC:
        // le altre non hanno profilo visitabile e non sono utili agli utenti.
        $filters['status']     = 'active';
        $filters['kyc_status'] = 'approved';
        [$directoryCompanies, $directoryStats, $sectorOptions, $sectorBuckets] = $this->buildCompanyDirectoryData($filters);

        return view('portal.companies', [
            'pageTitle' => 'Aziende del circuito',
            'currentAccount' => $currentAccount,
            'currentUser' => $currentUser,
            'companies' => $directoryCompanies,
            'directoryStats' => $directoryStats,
            'filters' => $filters,
            'sectorOptions' => $sectorOptions,
            'sectorBuckets' => $sectorBuckets,
            'directoryRoute' => route('portal.companies'),
            'directoryMode' => 'portal',
            'activeNav' => 'aziende',
        ]);
    }

    public function editProfile(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canOperateAccount($currentAccount), 403);

        $company = $currentAccount->company;
        abort_if($company === null, 404);

        return view('portal.profile-edit', [
            'pageTitle'      => 'Profilo azienda',
            'currentAccount' => $currentAccount,
            'currentUser'    => $currentUser,
            'company'        => $company,
            'sectors'        => Sector::activeList(),
            'activeNav'      => 'profile',
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canOperateAccount($currentAccount), 403);

        $company = $currentAccount->company;
        abort_if($company === null, 404);

        $validated = $request->validate([
            'sector'        => ['nullable', 'string', 'max:120', \Illuminate\Validation\Rule::in(Sector::activeList()->push('')->toArray())],
            'tagline'       => ['nullable', 'string', 'max:160'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'city'          => ['nullable', 'string', 'max:100'],
            'website'       => ['nullable', 'url', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'email'         => ['nullable', 'email', 'max:255'],
            'linkedin_url'  => ['nullable', 'url', 'max:255'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url'  => ['nullable', 'url', 'max:255'],
            'logo'          => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
            'banner'        => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
            'remove_logo'   => ['nullable', 'boolean'],
            'remove_banner' => ['nullable', 'boolean'],
        ]);

        $dir = 'companies/' . $company->uuid;

        // Handle logo
        if ($request->boolean('remove_logo')) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            $validated['logo_path'] = null;
        } elseif ($request->hasFile('logo')) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            $validated['logo_path'] = $request->file('logo')->store("{$dir}", 'public');
        }

        // Handle banner
        if ($request->boolean('remove_banner')) {
            if ($company->banner_path) {
                Storage::disk('public')->delete($company->banner_path);
            }
            $validated['banner_path'] = null;
        } elseif ($request->hasFile('banner')) {
            if ($company->banner_path) {
                Storage::disk('public')->delete($company->banner_path);
            }
            $validated['banner_path'] = $request->file('banner')->store("{$dir}", 'public');
        }

        // Remove helper fields before fill
        unset($validated['logo'], $validated['banner'], $validated['remove_logo'], $validated['remove_banner']);

        $company->fill($validated)->save();

        return redirect()->route('portal.profile.edit')
            ->with('success', 'Profilo aggiornato con successo.');
    }

    public function editPersonalProfile(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        [$currentAccount] = $this->resolveCurrentContext($user, $this->requestedCompanyId($request));

        abort_unless($currentAccount->owner_type === 'private', 403);

        return view('portal.personal-profile-edit', [
            'pageTitle'      => 'Il mio profilo',
            'currentAccount' => $currentAccount,
            'currentUser'    => $user,
            'activeNav'      => 'profilo',
        ]);
    }

    public function updatePersonalProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        [$currentAccount] = $this->resolveCurrentContext($user, $this->requestedCompanyId($request));

        abort_unless($currentAccount->owner_type === 'private', 403);

        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'city'          => ['nullable', 'string', 'max:100'],
            'bio'           => ['nullable', 'string', 'max:500'],
            'avatar'        => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('remove_avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $validated['avatar_path'] = null;
        } elseif ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $validated['avatar_path'] = $request->file('avatar')->store('avatars/' . $user->id, 'public');
        }

        unset($validated['avatar'], $validated['remove_avatar']);

        $user->fill($validated)->save();

        return redirect()->route('portal.personal-profile.edit')
            ->with('success', 'Profilo aggiornato con successo.');
    }

    public function showCompany(Request $request, Company $company): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canViewCompaniesDirectory(), 403);

        // Solo aziende approvate e attive visibili nel circuito
        abort_unless($company->status === 'active' && $company->kyc_status === 'approved', 404);

        $activeListings = $company->listings()
            ->where('status', 'active')
            ->orderByDesc('featured')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $activeAnnouncements = $company->announcements()
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        $totalVolume = Transfer::query()
            ->join('accounts', 'transfers.to_account_id', '=', 'accounts.id')
            ->where('accounts.company_id', $company->id)
            ->where('transfers.status', 'booked')
            ->sum('transfers.amount');

        return view('portal.company-show', [
            'pageTitle'          => $company->name,
            'currentAccount'     => $currentAccount,
            'currentUser'        => $currentUser,
            'company'            => $company,
            'activeListings'     => $activeListings,
            'activeAnnouncements'=> $activeAnnouncements,
            'totalVolume'        => (int) $totalVolume,
            'activeNav'          => 'aziende',
        ]);
    }

    public function movements(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canOperateAccount($currentAccount), 403);

        // Filtri (include sub_account_id)
        $filters = $this->movementsFilters($request);

        // Sottoconti del conto padre (per filtro e badge)
        $childAccounts = $currentAccount->isSubAccount()
            ? collect()
            : $currentAccount->childAccounts()->orderBy('account_name')->get();

        // Determina gli account ID da includere nella query
        if ($filters['sub_account_id'] && ! $currentAccount->isSubAccount()) {
            // Verifica che il sottoconto richiesto appartenga al conto corrente (prevenzione IDOR)
            $subAccountBelongs = $childAccounts->contains('id', (int) $filters['sub_account_id']);
            abort_unless($subAccountBelongs, 403, 'Sottoconto non autorizzato.');
            // Filtro per sottoconto specifico: solo quel conto
            $accountIds = [(int) $filters['sub_account_id']];
        } elseif (! $currentAccount->isSubAccount() && $childAccounts->isNotEmpty()) {
            // Conto padre senza filtro: padre + tutti i sottoconti
            $accountIds = $childAccounts->pluck('id')->prepend($currentAccount->id)->all();
        } else {
            $accountIds = [$currentAccount->id];
        }

        $query = $this->accountTransfersForIds($accountIds);

        // Filtro data da
        if ($filters['from']) {
            $query->where(fn ($q) => $q
                ->where(fn ($q2) => $q2->whereNotNull('booked_at')->where('booked_at', '>=', $filters['from'] . ' 00:00:00'))
                ->orWhere(fn ($q2) => $q2->whereNull('booked_at')->where('created_at', '>=', $filters['from'] . ' 00:00:00'))
            );
        }

        // Filtro data a
        if ($filters['to']) {
            $query->where(fn ($q) => $q
                ->where(fn ($q2) => $q2->whereNotNull('booked_at')->where('booked_at', '<=', $filters['to'] . ' 23:59:59'))
                ->orWhere(fn ($q2) => $q2->whereNull('booked_at')->where('created_at', '<=', $filters['to'] . ' 23:59:59'))
            );
        }

        // Filtro tipo (kind)
        if ($filters['kind']) {
            $query->where('kind', $filters['kind']);
        }

        // Filtro direzione (entrata/uscita) — riferita al conto principale o al sottoconto selezionato
        $directionAccountId = $filters['sub_account_id'] ?: $currentAccount->id;
        if ($filters['direction'] === 'in') {
            $query->where('to_account_id', $directionAccountId);
        } elseif ($filters['direction'] === 'out') {
            $query->where('from_account_id', $directionAccountId);
        }

        // Filtro stato
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        $transfers = $query->paginate(25)->withQueryString();

        return view('portal.movements', [
            'pageTitle'              => 'Lista movimenti',
            'currentAccount'         => $currentAccount,
            'currentUser'            => $currentUser,
            'transfers'              => $transfers,
            'filters'                => $filters,
            'childAccounts'          => $childAccounts,
            'currentBalance'         => (int) $currentAccount->available_balance,
            'availableBalance'       => $currentAccount->saldoDisponibile(),
            'massimale'              => $currentAccount->massimale(),
            'commercialAvailability' => $currentAccount->disponibilitaCommerciale(),
            'activeNav'              => 'movimenti',
        ]);
    }


    /**
     * Scarica i movimenti del conto corrente come file CSV.
     * Rispetta gli stessi filtri della pagina /movimenti.
     */
    public function exportMovementsCsv(Request $request): StreamedResponse|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canOperateAccount($currentAccount), 403);

        $filters  = $this->movementsFilters($request);
        $query    = $this->accountTransfers($currentAccount);
        $filename = 'movimenti-' . now()->format('Ymd-His') . '.csv';

        if ($filters['from']) {
            $query->where(fn ($q) => $q
                ->where(fn ($q2) => $q2->whereNotNull('booked_at')->where('booked_at', '>=', $filters['from'] . ' 00:00:00'))
                ->orWhere(fn ($q2) => $q2->whereNull('booked_at')->where('created_at', '>=', $filters['from'] . ' 00:00:00'))
            );
        }
        if ($filters['to']) {
            $query->where(fn ($q) => $q
                ->where(fn ($q2) => $q2->whereNotNull('booked_at')->where('booked_at', '<=', $filters['to'] . ' 23:59:59'))
                ->orWhere(fn ($q2) => $q2->whereNull('booked_at')->where('created_at', '<=', $filters['to'] . ' 23:59:59'))
            );
        }
        if ($filters['kind']) {
            $query->where('kind', $filters['kind']);
        }
        if ($filters['direction'] === 'in') {
            $query->where('to_account_id', $currentAccount->id);
        } elseif ($filters['direction'] === 'out') {
            $query->where('from_account_id', $currentAccount->id);
        }
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        return response()->streamDownload(function () use ($query, $currentAccount): void {
            $out = fopen('php://output', 'w');

            // BOM UTF-8 per compatibilita Excel
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Data', 'Tipo', 'Stato', 'Controparte',
                'Direzione', 'Importo KY', 'Causale', 'Riferimento',
            ], ';');

            $query->orderByDesc('booked_at')->chunk(500, function ($rows) use ($out, $currentAccount): void {
                foreach ($rows as $t) {
                    $isCredit      = (int) $t->to_account_id === $currentAccount->id;
                    $counterparty  = $isCredit
                        ? ($t->fromAccount?->company?->name ?? $t->fromAccount?->display_name ?? '-')
                        : ($t->toAccount?->company?->name  ?? $t->toAccount?->display_name  ?? '-');

                    fputcsv($out, [
                        $t->booked_at?->format('d/m/Y H:i') ?? '-',
                        $t->kind ?? '-',
                        $t->status,
                        $counterparty,
                        $isCredit ? 'ENTRATA' : 'USCITA',
                        ky_format($t->amount),
                        $t->description ?? '-',
                        $t->reference ?? '-',
                    ], ';');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Export prima nota in formato partita doppia per commercialisti italiani.
     * Solo trasferimenti "booked" — pending e cancelled non entrano in contabilita.
     */
    public function exportPrimaNota(Request $request): StreamedResponse|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canOperateAccount($currentAccount), 403);

        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('from', '')) ? $request->query('from') : '';
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('to', ''))   ? $request->query('to')   : '';

        $query = $this->accountTransfers($currentAccount)
            ->where('status', 'booked')
            ->when($from, fn ($q) => $q->where('booked_at', '>=', $from . ' 00:00:00'))
            ->when($to,   fn ($q) => $q->where('booked_at', '<=', $to   . ' 23:59:59'))
            ->orderBy('booked_at');

        $companySlug = $currentAccount->company?->slug ?? 'azienda';
        $filename    = 'prima-nota-' . $companySlug . '-' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($query, $currentAccount): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 per Excel

            fputcsv($out, [
                'Data',
                'N. Documento',
                'Descrizione / Causale',
                'Conto Dare',
                'Importo Dare (KY)',
                'Conto Avere',
                'Importo Avere (KY)',
                'Tipo operazione',
            ], ';');

            $query->chunk(500, function ($rows) use ($out, $currentAccount): void {
                foreach ($rows as $t) {
                    $isEntrata    = (int) $t->to_account_id === $currentAccount->id;
                    $controparte  = $isEntrata
                        ? ($t->fromAccount?->company?->name ?? $t->fromAccount?->display_name ?? 'Controparte')
                        : ($t->toAccount?->company?->name  ?? $t->toAccount?->display_name  ?? 'Controparte');

                    $importo      = ky_format($t->amount);
                    $descrizione  = $t->description ?: ('Operazione ' . ($t->kind ?? ''));
                    $data         = $t->booked_at?->format('d/m/Y') ?? now()->format('d/m/Y');
                    $ndoc         = strtoupper(substr($t->uuid ?? (string) $t->id, 0, 12));

                    if ($isEntrata) {
                        // Entrata: DARE = Cassa KY, AVERE = Clienti/controparte
                        fputcsv($out, [
                            $data,
                            $ndoc,
                            $descrizione,
                            'Cassa KY',
                            $importo,
                            'Clienti - ' . $controparte,
                            $importo,
                            'Entrata',
                        ], ';');
                    } else {
                        // Uscita: DARE = Fornitori/controparte, AVERE = Cassa KY
                        fputcsv($out, [
                            $data,
                            $ndoc,
                            $descrizione,
                            'Fornitori - ' . $controparte,
                            $importo,
                            'Cassa KY',
                            $importo,
                            'Uscita',
                        ], ';');
                    }
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function movementsFilters(Request $request): array
    {
        $kind      = trim((string) $request->query('kind', ''));
        $direction = trim((string) $request->query('direction', ''));
        $status    = trim((string) $request->query('status', ''));
        $subId     = (int) $request->query('sub_account_id', 0);

        $validKinds = [
            'trade_payment', 'portal_payment', 'portal_collection_request',
            'portal_installment', 'portal_netting', 'portal_refund',
            'portal_credit_note', 'portal_qr_payment',
            'portal_cashback', 'portal_fee',
        ];
        $validDirections = ['in', 'out'];
        $validStatuses   = ['booked', 'pending', 'cancelled'];

        return [
            'from'            => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('from', ''))  ? $request->query('from')  : '',
            'to'              => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('to', ''))    ? $request->query('to')    : '',
            'kind'            => in_array($kind, $validKinds, true)           ? $kind      : '',
            'direction'       => in_array($direction, $validDirections, true)  ? $direction : '',
            'status'          => in_array($status, $validStatuses, true)       ? $status    : '',
            'sub_account_id'  => $subId > 0 ? $subId : 0,
        ];
    }

    public function paymentsHub(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));

        // Ultimi 8 destinatari unici (escluso conto sistema e sé stessi)
        $recentRecipients = Transfer::where('from_account_id', $currentAccount->id)
            ->where('status', 'booked')
            ->with('toAccount')
            ->orderByDesc('booked_at')
            ->get()
            ->pluck('toAccount')
            ->filter(fn($a) => $a && !$a->is_system_account && $a->id !== $currentAccount->id)
            ->unique('id')
            ->take(8)
            ->values();

        $pendingCount = Transfer::where('to_account_id', $currentAccount->id)
            ->where('status', 'pending')
            ->count();

        return view('portal.pagamenti-hub', [
            'pageTitle'        => 'Invia & Ricevi',
            'currentAccount'   => $currentAccount,
            'currentUser'      => $currentUser,
            'currentBalance'   => $currentAccount->available_balance,
            'recentRecipients' => $recentRecipients,
            'pendingCount'     => $pendingCount,
            'activeNav'        => 'pagamenti',
        ]);
    }

    public function payForm(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($this->canSendPayments($request->user(), $currentAccount), 403);

        // Destinatari pre-selezionato via query string (dalla hub)
        $preselectedToId = (int) $request->query('to', 0);

        // Ultimi 6 destinatari per i chip rapidi
        $recentRecipients = Transfer::where('from_account_id', $currentAccount->id)
            ->where('status', 'booked')
            ->with('toAccount')
            ->orderByDesc('booked_at')
            ->get()
            ->pluck('toAccount')
            ->filter(fn($a) => $a && !$a->is_system_account && $a->id !== $currentAccount->id)
            ->unique('id')
            ->take(6)
            ->values();

        return view('portal.pay', [
            'pageTitle'        => 'Effettua un pagamento',
            'currentAccount'   => $currentAccount,
            'currentUser'      => $currentUser,
            'counterpartyAccounts' => $this->counterpartyAccounts($currentAccount),
            'recentRecipients' => $recentRecipients,
            'preselectedToId'  => $preselectedToId,
            'activeNav'        => 'conto',
        ]);
    }

    public function receiveForm(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($this->canReceivePayments($request->user(), $currentAccount), 403);

        return view('portal.receive', [
            'pageTitle' => 'Incassa',
            'currentAccount' => $currentAccount,
            'currentUser' => $currentUser,
            'counterpartyAccounts' => $this->counterpartyAccounts($currentAccount),
            'activeNav' => 'ricevi',
        ]);
    }

    public function scanner(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($this->canSendPayments($request->user(), $currentAccount), 403);

        return view('portal.scanner', [
            'pageTitle' => 'Scanner',
            'currentAccount' => $currentAccount,
            'currentUser' => $currentUser,
            'activeNav' => 'scanner',
        ]);
    }

    /**
     * Step 1 — valida il form e reindirizza alla schermata di conferma.
     */
    public function paySubmit(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($this->canSendPayments($request->user(), $currentAccount), 403);

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'description'   => ['nullable', 'string', 'max:255'],
        ]);

        $toAccount = Account::find((int) $validated['to_account_id']);

        // Memorizza i dati del pagamento in sessione (idempotency key inclusa)
        $request->session()->put('pay_preview', [
            'from_account_id' => $currentAccount->id,
            'to_account_id'   => (int) $validated['to_account_id'],
            'to_account_name' => $toAccount?->display_name,
            'amount_cents'    => ky_to_cents($validated['amount']),
            'description'     => $validated['description'] ?? null,
            'initiated_by'    => $currentUser->id,
            'idempotency_key' => (string) Str::uuid(),
            'company_id'      => $this->requestedCompanyId($request),
        ]);

        return redirect()->route('portal.pay.confirm');
    }

    /**
     * Step 2 — mostra la schermata di riepilogo e conferma.
     */
    public function payConfirmShow(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        $preview = $request->session()->get('pay_preview');
        if (! $preview) {
            return redirect()->route('portal.pay.form')->with('portal_error', 'Sessione scaduta. Compila nuovamente il pagamento.');
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $preview['company_id'] ?? null);
        abort_unless($this->canSendPayments($request->user(), $currentAccount), 403);

        $toAccount = Account::find($preview['to_account_id']);

        $settings = \App\Models\SystemSetting::userLimitDefaults();
        $totpThreshold = $settings->payment_confirm_totp_threshold;

        $needsStepUp = false;
        if ($totpThreshold !== null && $preview['amount_cents'] >= (int) $totpThreshold) {
            $verifiedAt = $request->session()->get('step_up_verified_at');
            $isStepUpValid = $verifiedAt
                && now()->diffInMinutes(\Carbon\Carbon::createFromTimestamp($verifiedAt)) < \App\Http\Middleware\RequireStepUp::STEP_UP_WINDOW_MINUTES;
            $needsStepUp = ! $isStepUpValid;
        }

        return view('portal.pay-confirm', [
            'pageTitle'      => 'Conferma pagamento',
            'currentAccount' => $currentAccount,
            'currentUser'    => $currentUser,
            'toAccount'      => $toAccount,
            'preview'        => $preview,
            'needsStepUp'    => $needsStepUp,
            'activeNav'      => 'conto',
        ]);
    }

    /**
     * Step 3 — esegue il trasferimento dopo la conferma.
     */
    public function payExecute(Request $request, TransferBookingService $bookingService): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        $preview = $request->session()->get('pay_preview');
        if (! $preview) {
            return redirect()->route('portal.pay.form')->with('portal_error', 'Sessione scaduta. Compila nuovamente il pagamento.');
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $preview['company_id'] ?? null);
        abort_unless($this->canSendPayments($request->user(), $currentAccount), 403);

        // Verifica step-up per importi sopra soglia
        $settings = \App\Models\SystemSetting::userLimitDefaults();
        $totpThreshold = $settings->payment_confirm_totp_threshold;
        if ($totpThreshold !== null && $preview['amount_cents'] >= (int) $totpThreshold) {
            $verifiedAt = $request->session()->get('step_up_verified_at');
            $isStepUpValid = $verifiedAt
                && now()->diffInMinutes(\Carbon\Carbon::createFromTimestamp($verifiedAt)) < \App\Http\Middleware\RequireStepUp::STEP_UP_WINDOW_MINUTES;
            if (! $isStepUpValid) {
                $request->session()->put('step_up_return_url', route('portal.pay.confirm'));
                return redirect()->route('portal.step-up.show')
                    ->with('step_up_reason', 'Per importi elevati devi confermare la tua identità prima di procedere.');
            }
        }

        try {
            $transfer = $bookingService->book([
                'initiated_by'    => $currentUser->id,
                'from_account_id' => $currentAccount->id,
                'to_account_id'   => $preview['to_account_id'],
                'amount'          => $preview['amount_cents'],
                'description'     => $preview['description'] ?? null,
                'kind'            => 'portal_payment',
                'idempotency_key' => $preview['idempotency_key'],
                'ip_address'      => $request->ip(),
            ]);
        } catch (\RuntimeException $exception) {
            return redirect()->route('portal.pay.form')->withInput()->with('portal_error', $exception->getMessage());
        }

        // Rimuove la sessione di preview ora che il pagamento è eseguito
        $request->session()->forget('pay_preview');

        // Notifica al destinatario (email + in-app)
        $toAccount = $transfer->toAccount;
        $toOwner = $toAccount?->ownerUser ?? $toAccount?->company?->users()->first();
        if ($toOwner) {
            Mail::to($toOwner->email)->queue(
                new PaymentReceived(
                    recipient: $toOwner,
                    transfer: $transfer,
                    fromAccount: $transfer->fromAccount,
                    toAccount: $toAccount,
                    balanceAfter: (int) $toAccount->available_balance,
                )
            );
            $toOwner->notify(new PaymentReceivedNotification(
                transfer: $transfer,
                fromAccount: $transfer->fromAccount,
                toAccount: $toAccount,
            ));
        }

        // Notifica al mittente (conferma pagamento inviato)
        Mail::to($currentUser->email)->queue(
            new PaymentSent(
                sender: $currentUser,
                transfer: $transfer,
                fromAccount: $currentAccount,
                toAccount: $toAccount,
                balanceAfter: (int) $currentAccount->available_balance,
            )
        );

        $recipientName = $toAccount?->display_name ?? 'destinatario';
        $amountFormatted = ky_format($transfer->amount);
        $bookedAt = $transfer->booked_at?->setTimezone('Europe/Rome')->format('d/m/Y \a\l\l\e H:i') ?? now()->format('d/m/Y \a\l\l\e H:i');
        return redirect()->route('portal.dashboard')->with('portal_success', "✓ {$amountFormatted} KY inviati a {$recipientName} — {$bookedAt}");
    }

    public function receiveSubmit(Request $request, TransferBookingService $bookingService): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($this->canReceivePayments($request->user(), $currentAccount), 403);

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        // Verifica che il conto debitore esista, sia attivo e non sia di sistema
        $fromAccount = Account::findOrFail((int) $validated['from_account_id']);
        abort_unless($fromAccount->status === 'active', 422, 'Il conto selezionato non è attivo. Impossibile inviare la richiesta.');
        abort_if($fromAccount->is_system_account, 422, 'Destinatario non valido.');
        abort_if($fromAccount->id === $currentAccount->id, 422, 'Non puoi inviare una richiesta di pagamento a te stesso.');

        try {
            $transfer = $bookingService->requestPayment([
                'initiated_by' => $currentUser->id,
                'from_account_id' => (int) $validated['from_account_id'],
                'to_account_id' => $currentAccount->id,
                'amount' => ky_to_cents($validated['amount']),
                'description' => $validated['description'] ?? null,
                'kind' => 'portal_collection_request',
                'idempotency_key' => (string) Str::uuid(),
                'ip_address' => $request->ip(),
            ]);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('portal_error', $exception->getMessage());
        }

        // Notifica al debitore (email + in-app)
        $fromAccount = $transfer->fromAccount;
        $fromOwner = $fromAccount?->ownerUser ?? $fromAccount?->company?->users()->first();
        if ($fromOwner) {
            Mail::to($fromOwner->email)->queue(
                new PaymentRequested(
                    recipient: $fromOwner,
                    transfer: $transfer,
                    fromAccount: $fromAccount,
                    toAccount: $transfer->toAccount,
                    requesterName: $currentAccount->display_name,
                )
            );
            $fromOwner->notify(new PaymentRequestedNotification(
                transfer: $transfer,
                fromAccount: $fromAccount,
                toAccount: $transfer->toAccount,
            ));
        }

        $reqAmountFormatted = ky_format($transfer->amount);
        $reqToName = $fromAccount->display_name ?? 'il conto selezionato';
        return redirect()->route('portal.movements')->with('portal_success', "📤 Richiesta di {$reqAmountFormatted} KY inviata a {$reqToName} — in attesa di conferma.");
    }

    public function confirmReceiveRequest(Request $request, Transfer $transfer, TransferBookingService $bookingService): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($this->canSendPayments($request->user(), $currentAccount), 403);
        abort_unless($transfer->from_account_id === $currentAccount->id, 403);

        try {
            $confirmedTransfer = $bookingService->confirmRequest($transfer, $currentUser->id, $request->ip());
        } catch (\RuntimeException $exception) {
            return back()->with('portal_error', $exception->getMessage());
        }

        // Notifica al richiedente (email + in-app)
        $toAccount = $confirmedTransfer->toAccount;
        $toOwner = $toAccount?->ownerUser ?? $toAccount?->company?->users()->first();
        if ($toOwner) {
            Mail::to($toOwner->email)->queue(
                new PaymentRequestConfirmed(
                    recipient: $toOwner,
                    transfer: $confirmedTransfer,
                    fromAccount: $confirmedTransfer->fromAccount,
                    toAccount: $toAccount,
                )
            );
            $toOwner->notify(new PaymentRequestConfirmedNotification(
                transfer: $confirmedTransfer,
                fromAccount: $confirmedTransfer->fromAccount,
                toAccount: $toAccount,
            ));
        }

        $confirmedAmount = ky_format($confirmedTransfer->amount);
        $confirmedTo = $confirmedTransfer->toAccount?->display_name ?? 'il richiedente';
        $confirmedAt = $confirmedTransfer->booked_at?->setTimezone('Europe/Rome')->format('d/m/Y \a\l\l\e H:i') ?? now()->format('d/m/Y \a\l\l\e H:i');
        return redirect()->route('portal.movements')->with('portal_success', "✓ {$confirmedAmount} KY inviati a {$confirmedTo} — {$confirmedAt}");
    }

    public function rejectReceiveRequest(Request $request, Transfer $transfer, TransferBookingService $bookingService): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($this->canSendPayments($request->user(), $currentAccount), 403);
        abort_unless($transfer->from_account_id === $currentAccount->id, 403);

        try {
            $rejectedTransfer = $bookingService->rejectRequest($transfer, $currentUser->id, $request->ip());
        } catch (\RuntimeException $exception) {
            return back()->with('portal_error', $exception->getMessage());
        }

        // Notifica al richiedente (email + in-app)
        $toAccount = Account::query()->with(['company', 'ownerUser'])->find($rejectedTransfer->to_account_id);
        $toOwner = $toAccount?->ownerUser ?? $toAccount?->company?->users()->first();
        if ($toOwner && $toAccount) {
            $fromAccount = Account::query()->with(['company', 'ownerUser'])->find($rejectedTransfer->from_account_id);
            Mail::to($toOwner->email)->queue(
                new PaymentRequestRejected(
                    recipient: $toOwner,
                    transfer: $rejectedTransfer,
                    fromAccount: $fromAccount ?? $currentAccount,
                    toAccount: $toAccount,
                )
            );
            $toOwner->notify(new PaymentRequestRejectedNotification(
                transfer: $rejectedTransfer,
                fromAccount: $fromAccount ?? $currentAccount,
                toAccount: $toAccount,
            ));
        }

        return redirect()->route('portal.movements')->with('portal_success', 'Richiesta rifiutata.');
    }

    public function creditNoteForm(Request $request, ?Transfer $transfer = null): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($this->canSendPayments($request->user(), $currentAccount), 403);

        $linkedTransfer = null;
        if ($transfer && $transfer->to_account_id === $currentAccount->id && $transfer->status === 'booked') {
            $linkedTransfer = $transfer->load(['fromAccount.company', 'toAccount.company']);
        }

        return view('portal.credit-note', [
            'pageTitle'            => 'Emetti nota di credito',
            'activeNav'            => 'movimenti',
            'currentAccount'       => $currentAccount,
            'currentUser'          => $currentUser,
            'counterpartyAccounts' => $this->counterpartyAccounts($currentAccount),
            'linkedTransfer'       => $linkedTransfer,
        ]);
    }

    public function creditNoteSubmit(Request $request, TransferBookingService $bookingService): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($this->canSendPayments($request->user(), $currentAccount), 403);

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'to_account_id'        => ['required', 'integer', 'exists:accounts,id'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'description'          => ['nullable', 'string', 'max:255'],
            'original_transfer_id' => ['nullable', 'integer', 'exists:transfers,id'],
            'idempotency_key'      => ['nullable', 'uuid'],
        ]);

        try {
            $creditNote = $bookingService->issueCreditNote(
                fromAccountId:      $currentAccount->id,
                toAccountId:        (int) $validated['to_account_id'],
                amount:             ky_to_cents($validated['amount']),
                initiatedBy:        $currentUser->id,
                description:        $validated['description'] ?? null,
                originalTransferId: isset($validated['original_transfer_id']) ? (int) $validated['original_transfer_id'] : null,
                ipAddress:          $request->ip(),
                idempotencyKey:     $validated['idempotency_key'] ?? null,
            );
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('portal_error', $exception->getMessage());
        }

        $toAccount = $creditNote->toAccount;
        $toOwner   = $toAccount?->ownerUser ?? $toAccount?->company?->users()->first();
        if ($toOwner && $toAccount) {
            $originalTransfer = $creditNote->reversedTransfer;
            Mail::to($toOwner->email)->queue(new CreditNoteIssued(
                recipient:        $toOwner,
                creditNote:       $creditNote,
                fromAccount:      $creditNote->fromAccount ?? $currentAccount,
                toAccount:        $toAccount,
                balanceAfter:     (int) $toAccount->available_balance,
                originalTransfer: $originalTransfer,
            ));
            $toOwner->notify(new CreditNoteIssuedNotification(
                creditNote:       $creditNote,
                fromAccount:      $creditNote->fromAccount ?? $currentAccount,
                toAccount:        $toAccount,
                originalTransfer: $originalTransfer,
            ));
        }

        return redirect()->route('portal.movements')->with('portal_success',
            'Nota di credito di ' . ky_format($creditNote->amount) . ' KY emessa correttamente.'
        );
    }

    public function transferDetail(Request $request, Transfer $transfer): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));

        // L'utente deve essere parte del movimento (mittente o destinatario)
        $accountIds = $currentAccount->isSubAccount()
            ? [$currentAccount->id]
            : $currentAccount->childAccounts()->pluck('id')->prepend($currentAccount->id)->all();

        abort_unless(
            in_array($transfer->from_account_id, $accountIds, true) ||
            in_array($transfer->to_account_id, $accountIds, true),
            403
        );

        $transfer->load(['fromAccount.company', 'toAccount.company', 'initiator', 'relatedTransfer']);

        $isOutgoing = in_array($transfer->from_account_id, $accountIds, true);

        $refundableKinds = ['portal_payment', 'portal_payment_request', 'portal_collection_request', 'trade_payment', 'nfc_card', 'code'];
        $isRefundable = $transfer->status === 'booked'
            && ! $isOutgoing
            && in_array($transfer->kind, $refundableKinds, true);

        $alreadyRefunded = 0;
        $maxRefundable   = 0;
        if ($isRefundable) {
            $alreadyRefunded = (int) Transfer::query()
                ->where('reversed_transfer_id', $transfer->id)
                ->where('status', 'booked')
                ->sum('amount');
            $maxRefundable = (int) $transfer->amount - $alreadyRefunded;
            if ($maxRefundable <= 0) {
                $isRefundable = false;
            }
        }

        // Storico rimborsi collegati a questo movimento
        $relatedRefunds = Transfer::query()
            ->where('reversed_transfer_id', $transfer->id)
            ->where('status', 'booked')
            ->with(['fromAccount.company', 'toAccount.company'])
            ->orderByDesc('booked_at')
            ->get();

        return view('portal.transfer-detail', [
            'pageTitle'       => 'Dettaglio movimento',
            'activeNav'       => 'movimenti',
            'currentAccount'  => $currentAccount,
            'currentUser'     => $currentUser,
            'transfer'        => $transfer,
            'isOutgoing'      => $isOutgoing,
            'isRefundable'    => $isRefundable,
            'alreadyRefunded' => $alreadyRefunded,
            'maxRefundable'   => $maxRefundable,
            'relatedRefunds'  => $relatedRefunds,
        ]);
    }

    public function refundForm(Request $request, Transfer $transfer): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));

        // Only the recipient of the original transfer can refund
        abort_unless($transfer->to_account_id === $currentAccount->id, 403);
        abort_unless($transfer->status === 'booked', 403);

        $alreadyRefunded = Transfer::query()
            ->where('reversed_transfer_id', $transfer->id)
            ->where('status', 'booked')
            ->sum('amount');

        $maxRefundable = (int) $transfer->amount - (int) $alreadyRefunded;
        abort_unless($maxRefundable > 0, 422);

        return view('portal.refund', [
            'pageTitle'       => 'Emetti rimborso',
            'activeNav'       => 'movimenti',
            'currentAccount'  => $currentAccount,
            'currentUser'     => $currentUser,
            'transfer'        => $transfer->load(['fromAccount.company', 'toAccount.company']),
            'alreadyRefunded' => (int) $alreadyRefunded,
            'maxRefundable'   => $maxRefundable,
        ]);
    }

    public function refundSubmit(Request $request, Transfer $transfer, TransferBookingService $bookingService): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));

        abort_unless($transfer->to_account_id === $currentAccount->id, 403);
        abort_unless($transfer->status === 'booked', 403);

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'description'     => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        try {
            $refund = $bookingService->refundMerchant(
                originalTransfer: $transfer,
                refundAmount:     ky_to_cents($validated['amount']),
                initiatedBy:      $currentUser->id,
                description:      $validated['description'] ?? null,
                ipAddress:        $request->ip(),
                idempotencyKey:   $validated['idempotency_key'] ?? null,
            );
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('portal_error', $exception->getMessage());
        }

        // Notify the original payer (toAccount of refund = fromAccount of original)
        $beneficiaryAccount = $refund->toAccount;
        $beneficiaryOwner   = $beneficiaryAccount?->ownerUser ?? $beneficiaryAccount?->company?->users()->first();
        if ($beneficiaryOwner && $beneficiaryAccount) {
            Mail::to($beneficiaryOwner->email)->queue(new RefundIssued(
                recipient:         $beneficiaryOwner,
                refundTransfer:    $refund,
                originalTransfer:  $transfer,
                fromAccount:       $refund->fromAccount ?? $currentAccount,
                toAccount:         $beneficiaryAccount,
                balanceAfter:      (int) $beneficiaryAccount->available_balance,
            ));
            $beneficiaryOwner->notify(new RefundIssuedNotification(
                refundTransfer:   $refund,
                originalTransfer: $transfer,
                fromAccount:      $refund->fromAccount ?? $currentAccount,
                toAccount:        $beneficiaryAccount,
            ));
        }

        return redirect()->route('portal.movements')->with('portal_success',
            'Rimborso di ' . ky_format($refund->amount) . ' KY emesso correttamente.'
        );
    }

    public function paymentRequests(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));

        // Richieste ricevute: io devo pagare (from_account_id = me)
        $receivedRequests = Transfer::query()
            ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser'])
            ->where('from_account_id', $currentAccount->id)
            ->where('kind', 'portal_collection_request')
            ->orderByDesc('created_at')
            ->get();

        // Richieste inviate: io ho chiesto il pagamento (to_account_id = me)
        $sentRequests = Transfer::query()
            ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser'])
            ->where('to_account_id', $currentAccount->id)
            ->where('kind', 'portal_collection_request')
            ->orderByDesc('created_at')
            ->get();

        $pendingReceived   = $receivedRequests->where('status', 'pending');
        $confirmedReceived = $receivedRequests->where('status', 'booked')->take(30);
        $rejectedReceived  = $receivedRequests->where('status', 'rejected')->take(30);

        $pendingSent   = $sentRequests->where('status', 'pending');
        $confirmedSent = $sentRequests->where('status', 'booked')->take(30);
        $rejectedSent  = $sentRequests->where('status', 'rejected')->take(30);

        $confirmedReceivedTotal = $receivedRequests->where('status', 'booked')->count();
        $confirmedSentTotal     = $sentRequests->where('status', 'booked')->count();

        // ── Richieste formali (TextPaymentRequest) ────────────────────────────
        $formalReceived = TextPaymentRequest::query()
            ->with(['fromAccount.company', 'fromAccount.ownerUser'])
            ->where('to_account_id', $currentAccount->id)
            ->latest()
            ->get();

        $formalSent = TextPaymentRequest::query()
            ->with(['toAccount.company', 'toAccount.ownerUser'])
            ->where('from_account_id', $currentAccount->id)
            ->latest()
            ->get();

        return view('portal.payment-requests', [
            'pageTitle'              => 'Richieste',
            'activeNav'              => 'richieste',
            'currentAccount'         => $currentAccount,
            'currentUser'            => $currentUser,
            'pendingReceived'        => $pendingReceived,
            'confirmedReceived'      => $confirmedReceived,
            'rejectedReceived'       => $rejectedReceived,
            'pendingSent'            => $pendingSent,
            'confirmedSent'          => $confirmedSent,
            'rejectedSent'           => $rejectedSent,
            'confirmedReceivedTotal' => $confirmedReceivedTotal,
            'confirmedSentTotal'     => $confirmedSentTotal,
            'formalReceived'         => $formalReceived,
            'formalSent'             => $formalSent,
            'activeTab'              => request('tab', 'incasso'),
        ]);
    }

    public function notifications(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));

        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(30);

        return view('portal.notifications', [
            'pageTitle'     => 'Notifiche',
            'currentAccount'=> $currentAccount,
            'currentUser'   => $currentUser,
            'notifications' => $notifications,
            'activeNav'     => '',
        ]);
    }

    public function markNotificationRead(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $link = $notification->data['link'] ?? route('portal.notifications');

        return redirect($link);
    }

    public function markAllNotificationsRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('portal_success', 'Tutte le notifiche segnate come lette.');
    }

    protected function resolveCurrentContext(User $viewer, ?int $requestedCompanyId = null): array
    {
        abort_if($viewer->canAccessBackoffice(), 403);

        // Legacy single-delegate: user is bound to exactly one managed account
        if ($viewer->managed_account_id !== null) {
            $currentAccount = Account::query()
                ->with(['company', 'ownerUser', 'creditLimits', 'parentAccount'])
                ->whereKey($viewer->managed_account_id)
                ->firstOrFail();

            return [$currentAccount, $viewer, $currentAccount->parentAccount ?? $currentAccount];
        }

        // Multi-account switcher via session
        $sessionAccountId = session('active_account_id');
        if ($sessionAccountId) {
            $switched = Account::query()
                ->with(['company', 'ownerUser', 'creditLimits', 'parentAccount'])
                ->where('status', 'active')
                ->find($sessionAccountId);

            if ($switched && $viewer->canOperateOnAccount($switched)) {
                $rootAccount = $switched->parentAccount ?? $switched;
                return [$switched, $viewer, $rootAccount];
            }

            // Session stale or unauthorized — clear it
            session()->forget('active_account_id');
        }

        if ($viewer->company_id !== null) {
            abort_unless($requestedCompanyId === null || $requestedCompanyId === $viewer->company_id, 403);

            $currentAccount = Account::query()
                ->with(['company', 'ownerUser', 'creditLimits', 'parentAccount'])
                ->where('company_id', $viewer->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->orderBy('id')
                ->firstOrFail();

            return [$currentAccount, $viewer, $currentAccount];
        }

        $currentAccount = Account::query()
            ->with(['company', 'ownerUser', 'creditLimits', 'parentAccount'])
            ->where('owner_user_id', $viewer->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->firstOrFail();

        return [$currentAccount, $viewer, $currentAccount];
    }

    /**
     * POST /conto/switch — cambia il conto attivo in sessione.
     */
    public function switchAccount(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_if($request->user()->canAccessBackoffice(), 403);

        $accountId = (int) $request->input('account_id');

        if ($accountId === 0) {
            session()->forget('active_account_id');
            return redirect()->route('portal.dashboard');
        }

        $account = Account::query()
            ->where('status', 'active')
            ->findOrFail($accountId);

        abort_unless($request->user()->canOperateOnAccount($account), 403);

        session(['active_account_id' => $account->id]);

        return redirect()->route('portal.dashboard');
    }

    protected function requestedCompanyId(Request $request): ?int
    {
        return $request->filled('company_id') ? $request->integer('company_id') : null;
    }

    protected function redirectBackofficeUser(User $viewer): ?RedirectResponse
    {
        return $viewer->canAccessBackoffice() ? redirect()->route('admin.dashboard') : null;
    }

    protected function isDelegateView(User $viewer, Account $currentAccount): bool
    {
        return $viewer->managed_account_id !== null && $viewer->managed_account_id === $currentAccount->id;
    }

    private function canSendPayments(User $viewer, Account $currentAccount): bool
    {
        return $currentAccount->status === 'active' && $viewer->canSendFromAccount($currentAccount);
    }

    private function canReceivePayments(User $viewer, Account $currentAccount): bool
    {
        return $currentAccount->status === 'active' && $viewer->canReceiveIntoAccount($currentAccount);
    }

    /** Segna il tutorial come visualizzato per l'utente corrente. */
    public function dismissTutorial(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->user()->forceFill(['tutorial_shown_at' => now()])->save();
        return response()->json(['ok' => true]);
    }

    private function counterpartyAccounts(Account $currentAccount): Collection
    {
        return Account::query()
            ->with(['company', 'ownerUser'])
            ->where('status', 'active')
            ->whereKeyNot($currentAccount->id)
            ->orderBy('id')
            ->get();
    }

    private function accountTransfers(Account $currentAccount)
    {
        return $this->accountTransfersForIds([$currentAccount->id]);
    }

    /**
     * Query trasferimenti per uno o più account ID (padre + sottoconti).
     */
    private function accountTransfersForIds(array $accountIds)
    {
        return \App\Models\Transfer::query()
            ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser', 'initiator'])
            ->where(function ($query) use ($accountIds) {
                $query->whereIn('from_account_id', $accountIds)
                      ->orWhereIn('to_account_id', $accountIds);
            })
            ->orderByRaw('COALESCE(booked_at, created_at) DESC')
            ->latest('id');
    }

    protected function companyDirectoryFilters(Request $request): array
    {
        $status = trim((string) $request->query('status', ''));
        $kycStatus = trim((string) $request->query('kyc_status', ''));

        return [
            'q' => trim((string) $request->query('q', '')),
            'sector' => trim((string) $request->query('sector', '')),
            'status' => in_array($status, ['active', 'suspended'], true) ? $status : '',
            'kyc_status' => in_array($kycStatus, ['approved', 'pending', 'rejected'], true) ? $kycStatus : '',
        ];
    }

    protected function buildCompanyDirectoryData(array $filters): array
    {
        $sectorBuckets = Company::query()
            ->selectRaw('sector, COUNT(*) as total')
            ->whereNotNull('sector')
            ->where('sector', '!=', '')
            ->groupBy('sector')
            ->orderBy('sector')
            ->get();

        $sectorOptions = $sectorBuckets->pluck('sector')->values();

        $companiesQuery = Company::query()
            ->withCount(['users', 'listings', 'announcements'])
            ->with(['users' => fn ($q) => $q->select(['id', 'company_id', 'account_holder_type'])])
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $search = $filters['q'];
                $query->where(function ($scope) use ($search): void {
                    $scope
                        ->where('name', 'like', '%' . $search . '%')
                        ->orWhere('slug', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('sector', 'like', '%' . $search . '%');
                });
            })
            ->when($filters['sector'] !== '', fn ($query) => $query->where('sector', $filters['sector']))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['kyc_status'] !== '', fn ($query) => $query->where('kyc_status', $filters['kyc_status']))
            ->orderByRaw("CASE
                WHEN subscription_plan = 'ecommerce'  THEN 0
                WHEN subscription_plan = 'vetrina'    THEN 1
                WHEN subscription_plan = 'biglietto'  THEN 2
                WHEN subscription_plan = 'anagrafica' THEN 3
                ELSE 4 END");

        $directoryStatsCompanies = (clone $companiesQuery)->get();
        $randomExpression = DB::getDriverName() === 'sqlite' ? 'RANDOM()' : 'RAND()';

        $directoryCompanies = $companiesQuery
            ->orderByRaw($randomExpression)
            ->paginate(48)
            ->withQueryString()
            ->through(function (Company $company) {
            return [
                'company'             => $company,
                'listings_count'      => (int) $company->listings_count,
                'announcements_count' => (int) $company->announcements_count,
                'is_private'          => $company->users->first()?->account_holder_type === 'private',
            ];
        });

        $directoryStats = [
            'companies' => $directoryStatsCompanies->count(),
            'sectors'   => $sectorOptions->count(),
            'verified'  => $directoryStatsCompanies->filter(fn (Company $company) => $company->kyc_status === 'approved')->count(),
            'listings'  => $directoryStatsCompanies->sum('listings_count'),
        ];

        return [$directoryCompanies, $directoryStats, $sectorOptions, $sectorBuckets];
    }

    public function creditLimitView(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canOperateAccount($currentAccount), 403);

        $activeLimit  = $currentAccount->activeCreditLimit();
        $limitHistory = $currentAccount->creditLimits()
            ->orderByDesc('id')
            ->take(10)
            ->get();

        $pendingRequest = $currentAccount->pendingCreditLimitRequest();
        $recentRequest  = !$pendingRequest
            ? $currentAccount->creditLimitRequests()->whereIn('status', ['approved', 'rejected'])->latest()->first()
            : null;

        return view('portal.credit-limit', [
            'pageTitle'      => 'Limite di credito',
            'currentAccount' => $currentAccount,
            'currentUser'    => $currentUser,
            'activeLimit'    => $activeLimit,
            'massimale'      => $currentAccount->massimale(),
            'limitHistory'   => $limitHistory,
            'pendingRequest' => $pendingRequest,
            'recentRequest'  => $recentRequest,
            'activeNav'      => 'fido',
        ]);
    }

    // ---- Fido request (portale) ------------------------------------------

    public function storeFidoRequest(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canOperateAccount($currentAccount), 403);

        // Blocca se c'e' gia' una richiesta pending
        if ($currentAccount->pendingCreditLimitRequest()) {
            return back()->with('error', "Hai già una richiesta in attesa di valutazione.");
        }

        $request->merge(['requested_amount' => str_replace(',', '.', (string) $request->input('requested_amount'))]);

        $validated = $request->validate([
            'requested_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'reason'           => ['nullable', 'string', 'max:1000'],
        ], [
            'requested_amount.required' => "Inserisci l'importo del fido richiesto.",
            'requested_amount.min'      => "L'importo minimo è 0,01 KY.",
            'requested_amount.max'      => "L'importo massimo richiedibile è 9.999.999 KY.",
        ]);

        $validated['requested_amount'] = ky_to_cents($validated['requested_amount']);

        $creditRequest = $currentAccount->creditLimitRequests()->create($validated);

        // Notifica tutti gli admin
        \App\Models\User::where('is_super_admin', true)->each(function ($admin) use ($creditRequest) {
            $admin->notify(new \App\Notifications\CreditLimitRequested($creditRequest));
        });

        return back()->with('success', "Richiesta inviata. Riceverai una notifica appena l'operatore avrà preso una decisione.");
    }

    public function balanceHistory(Request $request): \Illuminate\Http\JsonResponse
    {
        [$currentAccount] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));

        $days  = (int) $request->query('days', 30);
        $days  = in_array($days, [7, 30, 90], true) ? $days : 30;

        $now   = \Carbon\CarbonImmutable::now();
        $start = $now->subDays($days - 1)->startOfDay();

        // Calcola il saldo corrente (fine giornata oggi)
        $currentBalance = $currentAccount->available_balance;

        // Leggi tutti i transfer nel periodo + prima per ricostruire saldo
        $allTransfers = \App\Models\Transfer::query()
            ->where('status', 'booked')
            ->where(function ($q) use ($currentAccount) {
                $q->where('from_account_id', $currentAccount->id)
                  ->orWhere('to_account_id', $currentAccount->id);
            })
            ->where('booked_at', '>=', $start)
            ->orderBy('booked_at')
            ->get(['from_account_id', 'to_account_id', 'amount', 'booked_at']);

        // Costruiamo i net giornalieri
        $dailyNet = [];
        foreach ($allTransfers as $t) {
            $day = $t->booked_at->toDateString();
            $dailyNet[$day] = ($dailyNet[$day] ?? 0)
                + ($t->to_account_id === $currentAccount->id ? (int)$t->amount : -(int)$t->amount);
        }

        // Ricostruiamo saldo giorno per giorno a ritroso partendo dal saldo attuale
        $dates   = [];
        $cursor  = $now->toDateString();
        $balance = (int)$currentBalance;
        $result  = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $now->subDays($i)->toDateString();
            $dates[] = $day;
        }
        $dates = array_reverse($dates); // dal piu vecchio al piu recente

        // Calcola saldo per ogni giorno avanzando dalla data piu lontana
        // Prima: saldo iniziale = saldo attuale - somma di tutti i net dal $start ad oggi
        $totalNet = array_sum($dailyNet);
        $startBalance = (int)$currentBalance - $totalNet;

        $runningBalance = $startBalance;
        foreach ($dates as $date) {
            $runningBalance += ($dailyNet[$date] ?? 0);
            $result[] = [
                'date'    => $date,
                'balance' => round($runningBalance / 100, 2),
            ];
        }

        return response()->json($result);
    }

    public function togglePaymentsPause(Request $request): \Illuminate\Http\RedirectResponse
    {
        [$currentAccount, $currentUser, $rootAccount] = $this->resolveCurrentContext($request->user(), $this->requestedCompanyId($request));

        abort_unless($currentUser->is($rootAccount->ownerUser), 403);

        $company = $rootAccount->company;
        abort_unless($company !== null, 403);

        if ($company->payments_paused_at) {
            $company->update(['payments_paused_at' => null]);
            $msg = 'Pagamenti automatici ripristinati.';
        } el