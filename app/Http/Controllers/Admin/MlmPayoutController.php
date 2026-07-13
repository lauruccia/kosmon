<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MlmPayout;
use App\Models\User;
use App\Services\MlmPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/**
 * Backoffice: gestione liquidazioni EUR MLM (mlm_payouts). Vedi
 * MLM_PROPOSAL.md §5-6 e MlmPayoutService.
 */
class MlmPayoutController extends Controller
{
    use AuthorizesBackoffice;

    public function index(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $status = $request->query('status', '');

        $payouts = MlmPayout::with(['agent'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('period_from')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $kpis = [
            'pending'  => MlmPayout::where('status', 'pending')->count(),
            'approved' => MlmPayout::where('status', 'approved')->count(),
            'paid_total_eur_cents' => (int) MlmPayout::where('status', 'paid')->sum('total_eur_cents'),
            'pending_total_eur_cents' => (int) MlmPayout::whereIn('status', ['pending', 'approved'])->sum('total_eur_cents'),
        ];

        return view('admin.mlm.payouts.index', [
            'pageTitle' => 'Liquidazioni EUR — MLM',
            'activeNav' => 'mlm',
            'payouts'   => $payouts,
            'status'    => $status,
            'kpis'      => $kpis,
        ]);
    }

    public function show(Request $request, MlmPayout $mlmPayout): View
    {
        $this->authorizeBackoffice($request->user());

        $mlmPayout->load([
            'agent.mlmPaymentDetail',
            'approvedBy',
            'commissions.sourceClient',
            'commissions.sourceAgent',
            'bonusPayouts.event',
        ]);

        return view('admin.mlm.payouts.show', [
            'pageTitle' => 'Liquidazione #' . $mlmPayout->id,
            'activeNav' => 'mlm',
            'payout'    => $mlmPayout,
        ]);
    }

    public function generate(Request $request, MlmPayoutService $service): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $month = Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth();

        $payouts = $service->generateForMonth($month);

        return redirect()
            ->route('admin.mlm.payouts.index')
            ->with('portal_success', "Generazione completata per {$month->format('m/Y')}: {$payouts->count()} liquidazioni create o aggiornate.");
    }

    /**
     * Esegue subito `mlm:calculate-commissions` (normalmente schedulato il 1°
     * di ogni mese alle 02:00) per il mese indicato, cosi' da poter generare
     * le commissioni dirette/indirette senza aspettare il cron ne' avere
     * accesso al terminale — stesso pattern di
     * MlmSettingsController::recalculateNow() per mlm:recalculate-points.
     * Introdotto il 2026-07-13 su richiesta di Laura (kosmopay.it, sito di
     * test senza cron configurato: /admin/mlm-payouts risultava vuoto perche'
     * le commissioni non venivano mai calcolate).
     */
    public function calculateCommissions(Request $request): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        Artisan::call('mlm:calculate-commissions', ['--month' => $validated['month']]);
        $output = trim(Artisan::output());

        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event' => 'admin.mlm.manual_calculate_commissions',
            'auditable_type' => User::class,
            'auditable_id' => $request->user()->id,
            'context' => ['month' => $validated['month'], 'output' => $output],
        ]);

        return redirect()
            ->route('admin.mlm.payouts.index')
            ->with('portal_success', 'Calcolo commissioni eseguito. ' . $output);
    }

    public function approve(Request $request, MlmPayout $mlmPayout, MlmPayoutService $service): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        try {
            $service->approve($mlmPayout, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', 'Liquidazione approvata.');
    }

    public function markPaid(Request $request, MlmPayout $mlmPayout, MlmPayoutService $service): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'payment_reference' => ['required', 'string', 'max:100'],
            'admin_notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->markPaid($mlmPayout, $request->user(), $validated['payment_reference'], $validated['admin_notes'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', 'Liquidazione segnata come pagata.');
    }

    public function reject(Request $request, MlmPayout $mlmPayout, MlmPayoutService $service): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->reject($mlmPayout, $request->user(), $validated['admin_notes'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return redirect()
            ->route('admin.mlm.payouts.index')
            ->with('portal_success', 'Liquidazione rifiutata.');
    }
}
