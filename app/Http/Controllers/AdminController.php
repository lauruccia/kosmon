<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\NettingProposal;
use App\Models\PaymentPlan;
use App\Models\ScheduledPayment;
use App\Models\TextPaymentRequest;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Listing;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Concerns\HandlesMovementFilters;
use App\Services\TransferBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\StreamedResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    private const REFUND_WINDOW_DAYS = 30;
    private const USERS_PER_PAGE = 25;

    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    use HandlesMovementFilters;
    use AuthorizesBackoffice;

    public function dashboard(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $movementFilters = $this->movementFilters($request);
        $dashboardTransfersQuery = $this->movementQuery();
        $this->applyMovementDateFilters($dashboardTransfersQuery, $movementFilters);

        return view('admin.dashboard', [
            'pageTitle'               => 'Superadmin KMoney',
            'stats'                   => $this->stats(),
            'circuitKpis'             => $this->circuitKpis(),
            'monthlyChart'            => $this->monthlyChartData(),
            'topCompanies'            => $this->topCompaniesByVolume(),
            'refundWindowDays'        => self::REFUND_WINDOW_DAYS,
            'dashboardTransfers'      => $dashboardTransfersQuery->latest('booked_at')->latest('id')->take(30)->get(),
            'dashboardMovementTotals' => $this->movementTotals(clone $dashboardTransfersQuery),
            'movementFilters'         => $movementFilters,
            'movementPeriodOptions'   => $this->movementPeriodOptions(),
            'activeNav'               => 'admin',
        ]);
    }

    public function clearCache(Request $request): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        \Illuminate\Support\Facades\Artisan::call('cache:clear');
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        \Illuminate\Support\Facades\Artisan::call('route:clear');
        \Illuminate\Support\Facades\Artisan::call('view:clear');

        return back()->with('success', 'Cache svuotata con successo.');
    }




    public function transfers(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $movementFilters = $this->movementFilters($request, 'current_quarter');
        $transfersQuery = $this->movementQuery();
        $this->applyMovementDateFilters($transfersQuery, $movementFilters);

        // Ricerca per nome utente (mittente o destinatario)
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $transfersQuery->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($search): void {
                $like = '%' . $search . '%';
                // display_name è un accessor: la colonna reale è account_name,
                // con fallback su users.name (privati) e companies.name (aziende)
                $q->whereHas('fromAccount', function ($q2) use ($like): void {
                    $q2->where('account_name', 'like', $like)
                        ->orWhereHas('ownerUser', fn ($u) => $u->where('name', 'like', $like))
                        ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like));
                })->orWhereHas('toAccount', function ($q2) use ($like): void {
                    $q2->where('account_name', 'like', $like)
                        ->orWhereHas('ownerUser', fn ($u) => $u->where('name', 'like', $like))
                        ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like));
                });
            });
        }

        return view('admin.transfers', [
            'pageTitle' => 'Movimenti e correzioni',
            'refundWindowDays' => self::REFUND_WINDOW_DAYS,
            'transfers' => $transfersQuery->latest('booked_at')->latest('id')->get(),
            'movementTotals' => $this->movementTotals(clone $transfersQuery),
            'movementFilters' => $movementFilters,
            'movementPeriodOptions' => $this->movementPeriodOptions(),
            'supportsTransferRefunds' => $this->supportsTransferRefunds(),
            'activeNav' => 'transfers',
            'search' => $search,
        ]);
    }






    public function refundTransfer(Request $request, Transfer $transfer, TransferBookingService $bookingService): RedirectResponse
    {
        abort_unless($this->supportsTransferRefunds(), 409, 'Aggiorna il database per abilitare refund e storni amministrativi.');
        $this->authorizePermission($request->user(), 'movements.manage');

        abort_unless($transfer->status === 'booked', 422, 'Solo i movimenti contabilizzati possono essere stornati.');
        abort_unless($transfer->reversalChildren()->count() === 0, 422, 'Questo movimento e gia stato stornato.');
        abort_unless(
            $transfer->booked_at !== null && $transfer->booked_at->greaterThanOrEqualTo(CarbonImmutable::now()->subDays(self::REFUND_WINDOW_DAYS)),
            422,
            'La finestra di storno di ' . self::REFUND_WINDOW_DAYS . ' giorni e scaduta.'
        );

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $refund = $bookingService->book([
            'initiated_by' => $request->user()->id,
            'from_account_id' => $transfer->to_account_id,
            'to_account_id' => $transfer->from_account_id,
            'amount' => $transfer->amount,
            'description' => $validated['reason'] ?: 'Storno amministrativo di ' . $transfer->reference,
            'kind' => 'admin_refund',
            'idempotency_key' => (string) Str::uuid(),
            'ip_address' => $request->ip(),
        ]);

        $refund->forceFill([
            'reversed_transfer_id' => $transfer->id,
            'refunded_at' => CarbonImmutable::now(),
            'admin_action' => 'refund',
        ])->save();

        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event' => 'admin.transfer.refunded',
            'auditable_type' => Transfer::class,
            'auditable_id' => $refund->id,
            'ip_address' => $request->ip(),
            'context' => [
                'original_transfer_id' => $transfer->id,
                'refund_transfer_id' => $refund->id,
                'reason' => $validated['reason'] ?? null,
            ],
        ]);

        return back()->with('portal_success', 'Movimento stornato correttamente.');
    }

    private function stats(): array
    {
        return [
            'roles'        => Role::query()->count(),
            'permissions'  => Permission::query()->count(),
            'users'        => User::query()->count(),
            'superAdmins'  => User::query()->where('is_super_admin', true)->count(),
            'companies'    => Company::query()->count(),
            'accounts'     => Account::query()->count(),
            'transfers'    => Transfer::query()->count(),
            'companyUsers' => User::query()->where('account_holder_type', 'company')->count(),
            'privateUsers' => User::query()->where('account_holder_type', 'private')->count(),
        ];
    }

    /**
     * KPI specifici del circuito: liquidità, volumi, shop, annunci.
     */
    private function circuitKpis(): array
    {
        $now = CarbonImmutable::now();
        $startOfMonth  = $now->startOfMonth();
        $startOf30Days = $now->subDays(30)->startOfDay();

        // KY totali in circolazione (somma saldi positivi su conti primari attivi)
        $kyInCirculation = Account::query()
            ->where('status', 'active')
            ->sum('available_balance');

        // Volume movimentato nel mese corrente (solo booked)
        $volumeThisMonth = Transfer::query()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $startOfMonth)
            ->sum('amount');

        // Volume mese precedente (per calcolo variazione %)
        $startPrevMonth = $now->startOfMonth()->subMonth();
        $endPrevMonth   = $now->startOfMonth()->subSecond();
        $volumePrevMonth = Transfer::query()
            ->where('status', 'booked')
            ->whereBetween('booked_at', [$startPrevMonth, $endPrevMonth])
            ->sum('amount');

        $volumeChange = $volumePrevMonth > 0
            ? round((($volumeThisMonth - $volumePrevMonth) / $volumePrevMonth) * 100, 1)
            : null;

        // Nuovi utenti ultimi 30 giorni
        $newUsers30d = User::query()
            ->where('created_at', '>=', $startOf30Days)
            ->count();

        // Movimenti oggi
        $transfersToday = Transfer::query()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $now->startOfDay())
            ->count();

        // Shop e annunci attivi
        $activeListings      = Listing::query()->active()->count();
        $activeAnnouncements = Announcement::query()->active()->count();

        // Movimento medio per transazione (mese corrente)
        $avgMovement = Transfer::query()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $startOfMonth)
            ->avg('amount');

        return [
            'kyInCirculation'    => (int) $kyInCirculation,
            'volumeThisMonth'    => (int) $volumeThisMonth,
            'volumeChange'       => $volumeChange,      // null se nessun dato prev
            'newUsers30d'        => $newUsers30d,
            'transfersToday'     => $transfersToday,
            'activeListings'     => $activeListings,
            'activeAnnouncements'=> $activeAnnouncements,
            'avgMovement'        => $avgMovement ? (int) round($avgMovement) : 0,
        ];
    }

    /**
     * Serie mensile degli ultimi 6 mesi per il grafico linee.
     * Ritorna ['labels' => [...], 'volumes' => [...], 'counts' => [...]].
     */
    private function monthlyChartData(): array
    {
        $months = collect();
        $now = CarbonImmutable::now();

        for ($i = 5; $i >= 0; $i--) {
            $months->push($now->subMonths($i)->startOfMonth());
        }

        $periodExpression = DB::getDriverName() === 'mysql'
            ? "DATE_FORMAT(booked_at, '%Y-%m')"
            : "strftime('%Y-%m', booked_at)";

        // Recupera i dati dal DB con un'unica query
        $rows = Transfer::query()
            ->select(
                DB::raw("{$periodExpression} as ym"),
                DB::raw('SUM(amount) as total_volume'),
                DB::raw('COUNT(*) as total_count'),
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', $months->first())
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $labels  = [];
        $volumes = [];
        $counts  = [];

        foreach ($months as $month) {
            $ym  = $month->format('Y-m');
            $row = $rows->get($ym);

            $labels[]  = $month->locale('it')->isoFormat('MMM YY');
            $volumes[] = $row ? (int) $row->total_volume : 0;
            $counts[]  = $row ? (int) $row->total_count  : 0;
        }

        return compact('labels', 'volumes', 'counts');
    }

    /**
     * Top 5 aziende per volume movimentato (ultimi 90 giorni).
     */
    private function topCompaniesByVolume(): \Illuminate\Support\Collection
    {
        $since = CarbonImmutable::now()->subDays(90);

        // Raggruppa per azienda mittente
        $outgoing = Transfer::query()
            ->join('accounts', 'transfers.from_account_id', '=', 'accounts.id')
            ->join('companies', 'accounts.company_id', '=', 'companies.id')
            ->select('companies.name', DB::raw('SUM(transfers.amount) as volume'))
            ->where('transfers.status', 'booked')
            ->where('transfers.booked_at', '>=', $since)
            ->whereNotNull('accounts.company_id')
            ->groupBy('companies.name')
            ->orderByDesc('volume')
            ->limit(5)
            ->get();

        return $outgoing;
    }




    // ─── Report avanzato circuito ────────────────────────────────────────────

    public function report(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $filters = $this->movementFilters($request, 'current_month');
        $totals  = $this->movementTotals(
            $this->applyMovementDateFiltersReturn($this->movementQuery(), $filters)
        );

        return view('admin.report', [
            'pageTitle'           => 'Rapporti circuito',
            'filters'             => $filters,
            'periodOptions'       => $this->movementPeriodOptions(),
            'totals'              => $totals,
            'chartData'           => $this->reportChartData($filters),
            'topCompanies'        => $this->topCompaniesByVolumeForPeriod($filters['from'], $filters['to'], 10),
            'activeNav'           => 'report',
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorizeBackoffice($request->user());

        $filters = $this->movementFilters($request, 'all');
        $query   = $this->applyMovementDateFiltersReturn($this->movementQuery(), $filters);

        $filename = 'movimenti-kmoney-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            // BOM UTF-8 per Excel
            fwrite($out, "ï»¿");

            fputcsv($out, [
                'ID', 'Data', 'Stato', 'Tipo',
                'Da (azienda)', 'Da (conto)',
                'A (azienda)', 'A (conto)',
                'Importo KY', 'Causale', 'Idempotency key',
            ], ';');

            $query->orderBy('booked_at')->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $t) {
                    fputcsv($out, [
                        $t->id,
                        $t->booked_at?->format('d/m/Y H:i') ?? '-',
                        $t->status,
                        $t->kind ?? '-',
                        $t->fromAccount?->company?->name ?? $t->fromAccount?->display_name ?? '-',
                        $t->fromAccount?->account_number ?? '-',
                        $t->toAccount?->company?->name ?? $t->toAccount?->display_name ?? '-',
                        $t->toAccount?->account_number ?? '-',
                        ky_format($t->amount),
                        $t->description ?? '-',
                        $t->idempotency_key ?? '-',
                    ], ';');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ─────────────────────────────────────────────────────────────────────────


    /**
     * Dati serie temporale per il grafico nel report.
     * Granularita' automatica: giornaliera (<=14gg), settimanale (<=90gg), mensile (resto).
     */
    private function reportChartData(array $filters): array
    {
        $from = $filters['from'];
        $to   = $filters['to'];

        if (! $from || ! $to) {
            // Nessun filtro: ultimi 6 mesi mensili
            return $this->monthlyChartData();
        }

        $days = (int) $from->diffInDays($to);

        if ($days <= 14) {
            $granularity = 'day';
            $fmt = '%Y-%m-%d';
            $mysqlFmt = '%Y-%m-%d';
        } elseif ($days <= 90) {
            $granularity = 'week';
            $fmt = '%Y-%W';
            $mysqlFmt = '%Y-%u';
        } else {
            $granularity = 'month';
            $fmt = '%Y-%m';
            $mysqlFmt = '%Y-%m';
        }

        $periodExpression = DB::getDriverName() === 'mysql'
            ? "DATE_FORMAT(booked_at, '{$mysqlFmt}')"
            : "strftime('{$fmt}', booked_at)";

        $rows = Transfer::query()
            ->select(
                DB::raw("{$periodExpression} as period_key"),
                DB::raw('SUM(amount) as total_volume'),
                DB::raw('COUNT(*) as total_count'),
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->where('booked_at', '<=', $to)
            ->groupBy('period_key')
            ->orderBy('period_key')
            ->get();

        return [
            'labels'      => $rows->pluck('period_key')->all(),
            'volumes'     => $rows->map(fn($r) => (int) $r->total_volume)->all(),
            'counts'      => $rows->map(fn($r) => (int) $r->total_count)->all(),
            'granularity' => $granularity,
        ];
    }

    /**
     * Top N aziende per volume in un periodo.
     */
    private function topCompaniesByVolumeForPeriod(?CarbonImmutable $from, ?CarbonImmutable $to, int $limit = 10): \Illuminate\Support\Collection
    {
        $query = Transfer::query()
            ->join('accounts', 'transfers.from_account_id', '=', 'accounts.id')
            ->join('companies', 'accounts.company_id', '=', 'companies.id')
            ->select(
                'companies.name',
                'companies.slug',
                DB::raw('SUM(transfers.amount) as volume'),
                DB::raw('COUNT(*) as tx_count'),
            )
            ->where('transfers.status', 'booked')
            ->whereNotNull('accounts.company_id')
            ->when($from, fn($q) => $q->where('transfers.booked_at', '>=', $from))
            ->when($to,   fn($q) => $q->where('transfers.booked_at', '<=', $to))
            ->groupBy('companies.name', 'companies.slug')
            ->orderByDesc('volume')
            ->limit($limit);

        return $query->get();
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    public function analytics(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $days = (int) $request->input('days', 30);
        $from = now()->subDays($days);

        return view('admin.analytics', [
            'pageTitle'   => 'Analytics avanzate',
            'activeNav'   => 'analytics',
            'days'        => $days,

            // Webhook
            'webhookTotal'       => Webhook::count(),
            'webhookActive'      => Webhook::where('is_active', true)->count(),
            'webhookDeliveries'  => WebhookDelivery::where('created_at', '>=', $from)->count(),
            'webhookFailed'      => WebhookDelivery::where('created_at', '>=', $from)->where('success', false)->count(),
            'webhookByEvent'     => WebhookDelivery::where('created_at', '>=', $from)
                ->selectRaw('event, count(*) as cnt')
                ->groupBy('event')
                ->orderByDesc('cnt')
                ->limit(10)
                ->get(),

            // API tokens
            'apiTokenTotal'      => ApiToken::count(),
            'apiTokenActive'     => ApiToken::where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'apiTokenUsedRecent' => ApiToken::where('last_used_at', '>=', $from)->count(),

            // Piani rateali
            'planTotal'          => PaymentPlan::count(),
            'planActive'         => PaymentPlan::where('status', 'active')->count(),
            'planCompleted'      => PaymentPlan::where('status', 'completed')->count(),
            'planCancelled'      => PaymentPlan::where('status', 'cancelled')->count(),
            'planPendingApproval'=> PaymentPlan::where('status', 'pending_approval')->count(),

            // Pagamenti programmati
            'schedPending'       => ScheduledPayment::where('status', 'pending')->count(),
            'schedExecuted'      => ScheduledPayment::where('status', 'executed')->count(),
            'schedFailed'        => ScheduledPayment::where('status', 'failed')->count(),
            'schedCancelled'     => ScheduledPayment::where('status', 'cancelled')->count(),
            'schedRecent'        => ScheduledPayment::where('created_at', '>=', $from)->count(),

            // Richieste testuali
            'textTotal'          => TextPaymentRequest::count(),
            'textPending'        => TextPaymentRequest::where('status', 'pending')->count(),
            'textApproved'       => TextPaymentRequest::where('status', 'approved')->count(),
            'textRejected'       => TextPaymentRequest::where('status', 'rejected')->count(),
            'textCancelled'      => TextPaymentRequest::where('status', 'cancelled')->count(),

            // Netting
            'nettingTotal'       => NettingProposal::count(),
            'nettingPending'     => NettingProposal::where('status', 'pending')->count(),
            'nettingAccepted'    => NettingProposal::where('status', 'accepted')->count(),
            'nettingRejected'    => NettingProposal::where('status', 'rejected')->count(),
            'nettingVolume'      => NettingProposal::where('status', 'accepted')->sum('net_amount'),

            // Trend mensile (dual-driver: SQLite in dev, MySQL in prod)
            'schedMonthly'       => ScheduledPayment::where('created_at', '>=', now()->subMonths(6))
                ->selectRaw(
                    \DB::getDriverName() === 'sqlite'
                        ? "strftime('%Y-%m', created_at) as month, count(*) as cnt"
                        : "DATE_FORMAT(created_at, '%Y-%m') as month, count(*) as cnt"
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get(),
        ]);
    }


    // ── Circolazione monetaria / Velocity / Rete / Anomalie ──────────────────

    /** GET /admin/circuito */
    public function circuito(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $days = max(7, min(365, (int) $request->input('days', 30)));
        $from = now()->subDays($days)->startOfDay();
        $now  = now();
        $drv  = DB::getDriverName();

        // ── Velocity KY ──────────────────────────────────────────────────────
        $kyInCirculation = (int) Account::where('status', 'active')
            ->where('is_system_account', false)
            ->sum('available_balance');

        $volumePeriod = (int) Transfer::where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->sum('amount');

        $transactionCount = Transfer::where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->count();

        // Velocity = volume periodo / KY in circolazione (normalizzato a 30gg)
        $velocity30d = $kyInCirculation > 0
            ? round(($volumePeriod / $days * 30) / $kyInCirculation, 3)
            : 0;

        $rotationDays = $velocity30d > 0
            ? round(30 / $velocity30d, 1)
            : null;

        // Partecipanti attivi nel periodo (unique from + to account IDs)
        $fromIds = Transfer::where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->distinct()
            ->pluck('from_account_id');
        $toIds = Transfer::where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->distinct()
            ->pluck('to_account_id');
        $activeParticipants = $fromIds->merge($toIds)->unique()->count();

        $totalAccounts = Account::where('status', 'active')
            ->where('is_system_account', false)
            ->count();

        $participationRate = $totalAccounts > 0
            ? round($activeParticipants / $totalAccounts * 100, 1)
            : 0;

        // Trend velocity mensile (6 mesi)
        $periodExpr = $drv === 'mysql'
            ? "DATE_FORMAT(booked_at, '%Y-%m')"
            : "strftime('%Y-%m', booked_at)";

        $velocityTrend = Transfer::select(
                DB::raw("{$periodExpr} as ym"),
                DB::raw('SUM(amount) as volume'),
                DB::raw('COUNT(*) as cnt')
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', now()->subMonths(6)->startOfMonth())
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        // Saldo medio di sistema (per calcolo velocity su base aggregata)
        $avgBalance = Account::where('status', 'active')
            ->where('is_system_account', false)
            ->avg('available_balance');

        // ── Rete transazioni (top coppie per volume) ──────────────────────────
        $networkEdges = Transfer::select(
                'from_account_id',
                'to_account_id',
                DB::raw('SUM(amount) as volume'),
                DB::raw('COUNT(*) as count')
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->groupBy('from_account_id', 'to_account_id')
            ->orderByDesc('volume')
            ->limit(60)
            ->with(['fromAccount', 'toAccount'])
            ->get();

        // Nodi unici dalla rete
        $nodeIds = $networkEdges->pluck('from_account_id')
            ->merge($networkEdges->pluck('to_account_id'))
            ->unique();

        $nodeAccounts = Account::whereIn('id', $nodeIds)
            ->select('id', 'uuid', 'account_name', 'owner_type', 'company_id', 'owner_user_id', 'available_balance')
            ->with(['company:id,name', 'ownerUser:id,name'])
            ->get()
            ->keyBy('id');

        $networkNodes = $nodeAccounts->map(fn ($a) => [
            'id'    => $a->id,
            'label' => $a->company?->name ?? $a->ownerUser?->name ?? $a->account_name ?? "Conto #{$a->id}",
            'balance' => $a->available_balance,
        ])->values();

        $networkLinks = $networkEdges->map(fn ($e) => [
            'source' => $e->from_account_id,
            'target' => $e->to_account_id,
            'volume' => $e->volume,
            'count'  => $e->count,
        ])->values();

        // ── Anomalie automatiche ──────────────────────────────────────────────
        $anomalies = collect();

        // 1. Transazioni singole grandi (> 5× media del periodo)
        $avgAmount = $transactionCount > 0 && $volumePeriod > 0
            ? $volumePeriod / $transactionCount
            : 0;

        if ($avgAmount > 0) {
            $threshold = $avgAmount * 5;
            $largeTxs = Transfer::with(['fromAccount.company', 'toAccount.company'])
                ->where('status', 'booked')
                ->where('booked_at', '>=', $from)
                ->where('amount', '>', $threshold)
                ->orderByDesc('amount')
                ->limit(10)
                ->get();

            foreach ($largeTxs as $tx) {
                $anomalies->push([
                    'type'     => 'large_transaction',
                    'severity' => $tx->amount > $threshold * 3 ? 'high' : 'medium',
                    'title'    => 'Transazione anomala per importo',
                    'detail'   => sprintf(
                        '%s → %s: %s KY (%.0f× la media)',
                        $tx->fromAccount?->company?->name ?? $tx->fromAccount?->account_name ?? '?',
                        $tx->toAccount?->company?->name ?? $tx->toAccount?->account_name ?? '?',
                        ky_format($tx->amount),
                        $avgAmount > 0 ? $tx->amount / $avgAmount : 0
                    ),
                    'link'     => route('admin.transfers.index'),
                    'at'       => $tx->booked_at?->format('d/m/Y H:i'),
                ]);
            }
        }

        // 2. Burst: account con volume 24h > 3× loro media settimanale
        $last24h  = now()->subDay();
        $last7d   = now()->subDays(7);

        $burst24h = Transfer::select(
                'from_account_id',
                DB::raw('SUM(amount) as vol24h'),
                DB::raw('COUNT(*) as cnt24h')
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', $last24h)
            ->groupBy('from_account_id')
            ->having('cnt24h', '>=', 3)
            ->get()
            ->keyBy('from_account_id');

        if ($burst24h->isNotEmpty()) {
            $weekly = Transfer::select(
                    'from_account_id',
                    DB::raw('SUM(amount)/7.0 as avg_daily_vol')
                )
                ->where('status', 'booked')
                ->whereBetween('booked_at', [$last7d, $last24h])
                ->whereIn('from_account_id', $burst24h->keys())
                ->groupBy('from_account_id')
                ->get()
                ->keyBy('from_account_id');

            $burstAccounts = Account::whereIn('id', $burst24h->keys())
                ->with('company:id,name')
                ->get()
                ->keyBy('id');

            foreach ($burst24h as $accountId => $row) {
                $avgDaily = (float) ($weekly->get($accountId)?->avg_daily_vol ?? 0);
                if ($avgDaily > 0 && $row->vol24h > $avgDaily * 3) {
                    $account = $burstAccounts->get($accountId);
                    $anomalies->push([
                        'type'     => 'burst_activity',
                        'severity' => 'medium',
                        'title'    => 'Picco di attività insolito',
                        'detail'   => sprintf(
                            '%s: %s KY in 24h (%.0f× media giornaliera)',
                            $account?->company?->name ?? $account?->account_name ?? "Conto #{$accountId}",
                            ky_format((int) $row->vol24h),
                            $row->vol24h / $avgDaily
                        ),
                        'link'     => route('admin.accounts.show', $accountId),
                        'at'       => null,
                    ]);
                }
            }
        }

        // 3. Aziende vicine al limite di credito (saldo < 10% del massimale)
        $nearLimit = Account::with(['company:id,name', 'creditLimits'])
            ->where('status', 'active')
            ->where('is_system_account', false)
            ->where('allow_negative_balance', true)
            ->whereHas('creditLimits')
            ->get()
            ->filter(function ($a) {
                $limit = (int) ($a->activeCreditLimit()?->credit_limit ?? 0);
                return $limit > 0 && $a->available_balance < 0
                    && abs($a->available_balance) > $limit * 0.9;
            });

        foreach ($nearLimit as $a) {
            $climit = (int) ($a->activeCreditLimit()?->credit_limit ?? 0);
            $anomalies->push([
                'type'     => 'near_credit_limit',
                'severity' => 'high',
                'title'    => 'Conto al limite del fido',
                'detail'   => sprintf(
                    '%s: saldo %s KY (fido %s KY)',
                    $a->company?->name ?? $a->account_name,
                    ky_format($a->available_balance),
                    ky_format($climit)
                ),
                'link'     => route('admin.accounts.show', $a->id),
                'at'       => null,
            ]);
        }

        // 4. KYC non completato su account attivi con transazioni recenti
        $activeWithoutKyc = Account::select('accounts.id', 'accounts.account_name', 'accounts.company_id')
            ->join('transfers', function ($j) use ($from) {
                $j->on('accounts.id', '=', 'transfers.from_account_id')
                  ->where('transfers.status', 'booked')
                  ->where('transfers.booked_at', '>=', $from);
            })
            ->whereHas('company', function ($q) {
                $q->whereDoesntHave('kycDocuments', fn ($d) => $d->where('status', 'approved'))
                  ->where('status', 'active');
            })
            ->with('company:id,name')
            ->distinct()
            ->limit(10)
            ->get();

        foreach ($activeWithoutKyc as $a) {
            $anomalies->push([
                'type'     => 'kyc_missing',
                'severity' => 'medium',
                'title'    => 'KYC non approvato con attività recente',
                'detail'   => sprintf(
                    '%s ha transazioni nel periodo ma KYC non verificato',
                    $a->company?->name ?? $a->account_name
                ),
                'link'     => route('admin.kyc.index'),
                'at'       => null,
            ]);
        }

        // Ordina per severity
        $anomalies = $anomalies->sortBy(fn ($a) => match ($a['severity']) {
            'high'   => 0,
            'medium' => 1,
            default  => 2,
        })->values();

        return view('admin.circuito', [
            'pageTitle'         => 'Circolazione & Rete KY',
            'activeNav'         => 'circuito',
            'days'              => $days,

            // Velocity
            'kyInCirculation'   => $kyInCirculation,
            'volumePeriod'      => $volumePeriod,
            'transactionCount'  => $transactionCount,
            'velocity30d'       => $velocity30d,
            'rotationDays'      => $rotationDays,
            'activeParticipants'=> (int) $activeParticipants,
            'totalAccounts'     => $totalAccounts,
            'participationRate' => $participationRate,
            'avgAmount'         => $avgAmount ? (int) round($avgAmount) : 0,
            'avgBalance'        => $avgBalance ? (int) round($avgBalance) : 0,
            'velocityTrend'     => $velocityTrend,

            // Rete
            'networkNodes'      => $networkNodes,
            'networkLinks'      => $networkLinks,

            // Anomalie
            'anomalies'         => $anomalies,
        ]);
    }
}
