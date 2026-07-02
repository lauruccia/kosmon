<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\KyCardPurchase;
use App\Models\NettingProposal;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\PaymentRequest;
use App\Models\ScheduledPayment;
use App\Models\SubAccountLimitRequest;
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
use App\Models\SupportMessage;
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

        // Filtro per tipo di movimento (kind). Il valore sentinella LEDGER_OPENING_FILTER
        // seleziona la vista dedicata alle correzioni tecniche di apertura ledger, normalmente
        // escluse da tutte le liste del backoffice (vedi Transfer::excludeLedgerCorrections()).
        $kind = trim((string) $request->query('kind', ''));
        $isLedgerCorrectionsView = $kind === Transfer::LEDGER_OPENING_FILTER;

        $transfersQuery = $this->movementQuery($isLedgerCorrectionsView);
        $this->applyMovementDateFilters($transfersQuery, $movementFilters);

        if ($isLedgerCorrectionsView) {
            $transfersQuery->where('admin_action', Transfer::LEDGER_OPENING_ACTION);
        } elseif ($kind !== '') {
            $transfersQuery->where('kind', $kind);
        }

        // Filtro per stato (booked / pending / rejected)
        $statusFilter = trim((string) $request->query('status', ''));
        if (in_array($statusFilter, ['booked', 'pending', 'rejected'], true)) {
            $transfersQuery->where('status', $statusFilter);
        }

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
            'kindFilter' => $kind,
            'statusFilter' => $statusFilter,
            'movementKindOptions' => $this->movementKindOptions(),
            'canDeleteMovements' => (bool) $request->user()->is_super_admin,
            'isLedgerCorrectionsView' => $isLedgerCorrectionsView,
        ]);
    }

    /**
     * Etichette leggibili dei tipi di movimento, per il filtro nel backoffice.
     */
    private function movementKindOptions(): array
    {
        return [
            'trade_payment'             => 'Pag. commerciale',
            'portal_payment'            => 'Pag. portale',
            'portal_payment_request'    => 'Pag. richiesta',
            'portal_collection_request' => 'Incasso richiesta',
            'portal_refund'             => 'Rimborso',
            'admin_refund'              => 'Storno amm.',
            'portal_credit_note'        => 'Nota credito',
            'portal_fee'                => 'Commissione',
            'portal_cashback'           => 'Cashback',
            'portal_installment'        => 'Rata',
            'portal_netting'            => 'Netting',
            'ky_emission'               => 'Emissione KY',
        ];
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

    /**
     * Cancellazione FISICA di un singolo movimento (e dei suoi collegati).
     *
     * A differenza dello storno (che crea un contromovimento e conserva lo storico),
     * questa operazione rimuove davvero il Transfer e le sue LedgerEntry dal database,
     * ripristinando i saldi dei conti coinvolti. È pensata per ripulire movimenti di
     * prova/test. Il circuito resta a 0 perché ogni movimento eliminato è internamente
     * bilanciato (1 debit + 1 credit di pari importo): annullando entrambe le partite
     * la somma dei saldi non cambia.
     *
     * Solo super admin. L'emissione KY di sistema non è eliminabile da qui.
     */
    public function destroyTransfer(Request $request, Transfer $transfer): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403);

        $result = DB::transaction(function () use ($request, $transfer) {
            return $this->deleteTransferWithCascade($transfer, $request->user(), $request->ip());
        });

        $msg = "Eliminato il movimento {$transfer->reference}";
        if ($result['deleted'] > 1) {
            $msg .= " e {$result['linked']} movimenti collegati (commissioni/cashback/storni)";
        }
        $msg .= '. Saldi ripristinati, circuito ribilanciato.';

        return redirect()->route('admin.transfers.index', $request->only(['period', 'from_date', 'to_date', 'search', 'kind', 'status']))
            ->with('portal_success', $msg);
    }

    /**
     * Cancellazione FISICA multipla: elimina tutti i movimenti selezionati (con cascata).
     */
    public function bulkDestroyTransfers(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403);

        $validated = $request->validate([
            'transfer_ids'   => ['required', 'array', 'min:1'],
            'transfer_ids.*' => ['integer'],
        ]);

        $deleted = 0;
        $skipped = 0;

        DB::transaction(function () use ($request, $validated, &$deleted, &$skipped) {
            foreach ($validated['transfer_ids'] as $id) {
                $transfer = Transfer::find($id);

                // Già eliminato (es. era collegato a un altro selezionato) → salta.
                if ($transfer === null) {
                    continue;
                }

                // Protezione: l'emissione KY di sistema non è eliminabile.
                if ($transfer->kind === 'ky_emission') {
                    $skipped++;
                    continue;
                }

                $result = $this->deleteTransferWithCascade($transfer, $request->user(), $request->ip());
                $deleted += $result['deleted'];
            }
        });

        $msg = "Eliminati {$deleted} movimenti (collegati inclusi).";
        if ($skipped > 0) {
            $msg .= " {$skipped} emissioni KY di sistema sono state ignorate per sicurezza.";
        }

        return redirect()->route('admin.transfers.index', $request->only(['period', 'from_date', 'to_date', 'search', 'kind', 'status']))
            ->with('portal_success', $msg);
    }

    /**
     * Elimina fisicamente un movimento e i suoi collegati, ripristinando i saldi.
     *
     * Movimenti collegati eliminati a cascata:
     *  - Commissioni (kind portal_fee, related_transfer_id = movimento)
     *  - Cashback (idempotency_key = 'cashback_' . uuid del movimento)
     *  - Storni / rimborsi figli (reversed_transfer_id = movimento)
     *
     * Protetta contro un caso limite: i movimenti generati da una compensazione
     * (netting) accettata non sono eliminabili da qui (FK restrictOnDelete su
     * netting_proposals.net_transfer_id) — vedi abort_if sotto.
     *
     * Prima di toccare i saldi, riporta a uno stato coerente anche i record
     * esterni collegati via FK nullOnDelete (richieste di pagamento, rate,
     * pagamenti programmati, sforamenti sub-conto) — vedi
     * revertRecordsLinkedToDeletedTransfers().
     *
     * DEVE essere chiamato dentro una DB::transaction() dal chiamante.
     * Per ogni LedgerEntry eliminata, applica al conto l'effetto inverso:
     *  - credit (aveva aumentato il saldo) → sottrae l'importo
     *  - debit  (aveva diminuito il saldo) → aggiunge l'importo
     *
     * @return array{deleted:int, linked:int, ids:int[]}
     */
    private function deleteTransferWithCascade(Transfer $transfer, User $actor, ?string $ip): array
    {
        abort_if($transfer->kind === 'ky_emission', 422, "L'emissione di KY non può essere eliminata da questa pagina.");

        // ── Raccogli il set di movimenti da eliminare ──────────────────────────
        $ids = collect([$transfer->id]);

        // Commissioni collegate
        $ids = $ids->merge(Transfer::where('related_transfer_id', $transfer->id)->pluck('id'));

        // Cashback collegato (linkato solo via idempotency_key)
        if (! empty($transfer->uuid)) {
            $ids = $ids->merge(Transfer::where('idempotency_key', 'cashback_' . $transfer->uuid)->pluck('id'));
        }

        // Storni / rimborsi figli
        $ids = $ids->merge(Transfer::where('reversed_transfer_id', $transfer->id)->pluck('id'));

        $ids = $ids->unique()->values();

        // Protezione: un movimento generato da una compensazione (netting) accettata
        // è referenziato da netting_proposals.net_transfer_id con vincolo FK
        // restrictOnDelete — eliminarlo qui lascerebbe la proposta orfana e farebbe
        // fallire la query con un errore SQL grezzo. Blocchiamo prima di toccare i saldi.
        abort_if(
            NettingProposal::whereIn('net_transfer_id', $ids)->exists(),
            422,
            'Questo movimento è il saldo di una compensazione (netting) accettata e non può essere eliminato da qui.'
        );

        // Ripristina PRIMA lo stato dei record collegati (richieste di pagamento,
        // rate, pagamenti programmati...): le loro FK verso transfers sono
        // nullOnDelete, quindi dopo la delete perderemmo il modo di individuarli.
        $linkedReverted = $this->revertRecordsLinkedToDeletedTransfers($ids);

        // Carica con i ledger. Ordina in modo che il movimento padre sia eliminato
        // per ultimo (i figli lo referenziano via FK related/reversed).
        $transfers = Transfer::whereIn('id', $ids)
            ->with('ledgerEntries')
            ->get()
            ->sortBy(fn (Transfer $t) => $t->id === $transfer->id ? 1 : 0)
            ->values();

        $snapshot = [];

        foreach ($transfers as $t) {
            foreach ($t->ledgerEntries as $entry) {
                // Ricarica con lock: legge il saldo già aggiornato dalle iterazioni
                // precedenti (stesso conto può comparire più volte).
                $account = Account::query()->lockForUpdate()->find($entry->account_id);
                if ($account === null) {
                    continue;
                }

                $delta = $entry->direction === 'credit' ? -$entry->amount : $entry->amount;
                $account->forceFill([
                    'available_balance' => (int) $account->available_balance + (int) $delta,
                ])->save();
            }

            $snapshot[] = [
                'id'              => $t->id,
                'reference'       => $t->reference,
                'kind'            => $t->kind,
                'amount'          => $t->amount,
                'from_account_id' => $t->from_account_id,
                'to_account_id'   => $t->to_account_id,
            ];

            $t->ledgerEntries()->delete();
            $t->delete();
        }

        AuditLog::create([
            'actor_user_id'  => $actor->id,
            'event'          => 'admin.transfer.deleted',
            'auditable_type' => Transfer::class,
            'auditable_id'   => $transfer->id,
            'ip_address'     => $ip,
            'context'        => [
                'reason'             => 'cancellazione movimenti di test',
                'primary_reference'  => $transfer->reference,
                'deleted_transfers'  => $snapshot,
                'count'              => count($snapshot),
                'linked_reverted'    => $linkedReverted,
            ],
        ]);

        return [
            'deleted' => count($snapshot),
            'linked'  => max(0, count($snapshot) - 1),
            'ids'     => $ids->all(),
        ];
    }

    /**
     * Le FK verso transfers su richieste/rate/pagamenti programmati sono
     * nullOnDelete: dopo la cancellazione del movimento restava un record con
     * status "pagato/eseguito" ma transfer_id NULL — un'incongruenza nell'audit
     * trail (il record giurava che un pagamento era avvenuto, ma non esisteva
     * più nulla a dimostrarlo). Qui riportiamo esplicitamente questi record a
     * uno stato "annullato" coerente, PRIMA che la FK li spezzi.
     *
     * Nessuno di questi giri tocca available_balance: i saldi sono già stati
     * ripristinati dal ciclo sui ledger entries in deleteTransferWithCascade().
     *
     * `KyCardPurchase` è escluso di proposito: il pagamento reale (Stripe/
     * PayPal) è avvenuto FUORI dal circuito KY. Il suo status "completed"
     * resta l'unica verità corretta anche se il transfer KY collegato viene
     * eliminato — cambiarlo rischierebbe un doppio accredito se un webhook
     * duplicato dovesse ripassare di lì (creditKy() si affida a isCompleted()
     * come guardia di idempotenza). Ci limitiamo ad annotare admin_notes.
     *
     * Nessuno stato viene riportato a "pending": un ripristino automatico
     * rimetterebbe questi record nella coda dei job schedulati (es.
     * ProcessDueInstallments), innescando un nuovo movimento di denaro reale
     * senza che nessuno l'abbia deciso esplicitamente. Se serve, l'admin
     * ricrea la richiesta/rata a mano.
     *
     * @param \Illuminate\Support\Collection<int,int> $transferIds
     * @return array<string,int> conteggio dei record ripristinati per tipo (solo chiavi > 0)
     */
    private function revertRecordsLinkedToDeletedTransfers($transferIds): array
    {
        $note = 'Movimento collegato eliminato da un amministratore il ' . CarbonImmutable::now()->format('d/m/Y H:i') . '.';

        $counts = [];

        $counts['payment_requests'] = PaymentRequest::query()
            ->whereIn('transfer_id', $transferIds)
            ->update(['status' => 'cancelled', 'paid_at' => null]);

        $counts['text_payment_requests'] = TextPaymentRequest::query()
            ->whereIn('transfer_id', $transferIds)
            ->update(['status' => 'cancelled']);

        $counts['scheduled_payments'] = ScheduledPayment::query()
            ->whereIn('transfer_id', $transferIds)
            ->update(['status' => 'cancelled', 'executed_at' => null, 'failure_reason' => $note]);

        $revertedInstallments = PaymentPlanInstallment::query()
            ->whereIn('transfer_id', $transferIds)
            ->get(['id', 'payment_plan_id']);

        foreach ($revertedInstallments as $installment) {
            $installment->forceFill([
                'status'         => 'cancelled',
                'processed_at'   => null,
                'failure_reason' => $note,
            ])->save();

            // Se il piano era stato marcato "completed" solo grazie a questa rata, riaprilo:
            // non è più vero che tutte le rate sono state incassate.
            $plan = PaymentPlan::find($installment->payment_plan_id);
            if ($plan !== null && $plan->status === 'completed') {
                $plan->forceFill(['status' => 'active'])->save();
            }
        }
        $counts['payment_plan_installments'] = $revertedInstallments->count();

        // Sforamento (overdraft) sub-conto: il transfer che lo aveva consumato
        // non esiste più, quindi il credito concesso torna disponibile.
        $counts['sub_account_overdrafts'] = SubAccountLimitRequest::query()
            ->whereIn('overdraft_transfer_id', $transferIds)
            ->update(['overdraft_used' => false]);

        $annotatedPurchases = KyCardPurchase::query()
            ->whereIn('transfer_id', $transferIds)
            ->get(['id', 'admin_notes']);
        foreach ($annotatedPurchases as $purchase) {
            $purchase->forceFill([
                'admin_notes' => trim(($purchase->admin_notes ? $purchase->admin_notes . ' ' : '') . $note),
            ])->save();
        }
        $counts['ky_card_purchases_annotated'] = $annotatedPurchases->count();

        return array_filter($counts);
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
            'transfers'    => Transfer::query()->excludeLedgerCorrections()->count(),
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
            ->excludeLedgerCorrections()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $startOfMonth)
            ->sum('amount');

        // Volume mese precedente (per calcolo variazione %)
        $startPrevMonth = $now->startOfMonth()->subMonth();
        $endPrevMonth   = $now->startOfMonth()->subSecond();
        $volumePrevMonth = Transfer::query()
            ->excludeLedgerCorrections()
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
            ->excludeLedgerCorrections()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $now->startOfDay())
            ->count();

        // Shop e annunci attivi
        $activeListings      = Listing::query()->active()->count();
        $activeAnnouncements = Announcement::query()->active()->count();

        // Movimento medio per transazione (mese corrente)
        $avgMovement = Transfer::query()
            ->excludeLedgerCorrections()
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
            ->excludeLedgerCorrections()
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
            ->excludeLedgerCorrections()
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
            ->excludeLedgerCorrections()
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
            ->excludeLedgerCorrections()
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

        $volumePeriod = (int) Transfer::query()
            ->excludeLedgerCorrections()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->sum('amount');

        $transactionCount = Transfer::query()
            ->excludeLedgerCorrections()
            ->where('status', 'booked')
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
        $fromIds = Transfer::query()
            ->excludeLedgerCorrections()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->distinct()
            ->pluck('from_account_id');
        $toIds = Transfer::query()
            ->excludeLedgerCorrections()
            ->where('status', 'booked')
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

        $velocityTrend = Transfer::query()
            ->excludeLedgerCorrections()
            ->select(
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
        $networkEdges = Transfer::query()
            ->excludeLedgerCorrections()
            ->select(
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
            $largeTxs = Transfer::query()
                ->excludeLedgerCorrections()
                ->with(['fromAccount.company', 'toAccount.company'])
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

        $burst24h = Transfer::query()
            ->excludeLedgerCorrections()
            ->select(
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
            $weekly = Transfer::query()
                ->excludeLedgerCorrections()
                ->select(
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
                  ->where('transfers.booked_at', '>=', $from)
                  ->where(function ($q) {
                      $q->whereNull('transfers.admin_action')
                        ->orWhere('transfers.admin_action', '!=', \App\Models\Transfer::LEDGER_OPENING_ACTION);
                  });
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

    public function supportMessages(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $messages  = SupportMessage::with('user')->latest()->paginate(20);
        $openCount = SupportMessage::where('status', 'open')->count();

        return view('admin.support.index', [
            'pageTitle' => 'Messaggi assistenza',
            'activeNav' => 'support',
            'messages'  => $messages,
            'openCount' => $openCount,
        ]);
    }

    public function resolveSupport(Request $request, SupportMessage $message): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        if ($message->isOpen()) {
            $message->forceFill(['status' => 'resolved'])->save();

            AuditLog::create([
                'actor_user_id'  => $request->user()->id,
                'event'          => 'admin.support.resolved',
                'auditable_type' => SupportMessage::class,
                'auditable_id'   => $message->id,
                'ip_address'     => $request->ip(),
            ]);
        }

        return redirect()->route('admin.support.index')
            ->with('success', "Messaggio #{$message->id} segnato come risolto.");
    }
}
