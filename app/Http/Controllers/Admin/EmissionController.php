<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Transfer;
use App\Services\TransferBookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EmissionController extends Controller
{
    public function emitKyForm(Request $request): View
    {
        abort_unless($request->user()->is_super_admin, 403, 'Solo il super admin puo emettere KY.');

        $systemAccount = Account::systemAccount();
        abort_unless($systemAccount !== null, 500, 'Conto Cassa Circuito non trovato. Esegui le migration.');

        // ── Metriche circuito chiuso ──────────────────────────────────────────
        // In un circuito chiuso SUM(tutti i saldi) deve essere sempre 0.
        // Il saldo negativo della Cassa = KY netti in circolazione.
        $kyInCirculation    = (int) abs($systemAccount->available_balance);
        $circuitDelta       = (int) Account::query()->sum('available_balance'); // deve essere 0
        $circuitIsHealthy   = $circuitDelta === 0;

        // ── Flussi storici via trasferimento ──────────────────────────────────
        $totalOutFromSystem = (int) Transfer::query()
            ->where('from_account_id', $systemAccount->id)
            ->where('status', 'booked')
            ->sum('amount');

        $totalReturnedToSystem = (int) Transfer::query()
            ->where('to_account_id', $systemAccount->id)
            ->where('status', 'booked')
            ->sum('amount');

        // Saldo del conto sistema spiegato dai soli trasferimenti registrati.
        // La differenza rispetto al saldo reale = saldo impostato direttamente (seed / correzione contabile).
        $netViaTransfers  = $totalReturnedToSystem - $totalOutFromSystem;
        $implicitBalance  = abs($systemAccount->available_balance - $netViaTransfers);
        $hasImplicitBalance = $implicitBalance > 0;

        // ── Breakdown emissioni per tipo ──────────────────────────────────────
        $emissionBreakdown = Transfer::query()
            ->where('from_account_id', $systemAccount->id)
            ->where('status', 'booked')
            ->selectRaw('kind, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('kind')
            ->orderByDesc('total')
            ->get();

        $returnBreakdown = Transfer::query()
            ->where('to_account_id', $systemAccount->id)
            ->where('status', 'booked')
            ->selectRaw('kind, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('kind')
            ->orderByDesc('total')
            ->get();

        // ── Conto riserva operativa (MAIN account Knm srl — source delle distribuzioni) ──
        $mainReserveAccount = Account::query()
            ->with(['company:id,name'])
            ->where('type', 'main')
            ->where('is_system_account', false)
            ->orderBy('id')
            ->first();

        $kyOnMainReserve   = $mainReserveAccount ? (int) $mainReserveAccount->available_balance : 0;
        $kyOnOtherAccounts = $kyInCirculation - max(0, $kyOnMainReserve);

        // ── Fidi attivi ───────────────────────────────────────────────────────
        $activeCreditLimitsTotal = (int) \App\Models\CreditLimit::query()
            ->where('status', 'active')
            ->sum('credit_limit');

        // ── Conti con saldo positivo/negativo ────────────────────────────────
        $accountsPositive = Account::query()->where('is_system_account', false)->where('available_balance', '>', 0)->count();
        $accountsNegative = Account::query()->where('is_system_account', false)->where('available_balance', '<', 0)->count();

        // ── Dati per form ed storico ──────────────────────────────────────────
        $targetAccounts = Account::query()
            ->with(['company:id,name,kyc_status', 'ownerUser:id,name'])
            ->where('status', 'active')
            ->where('currency_code', 'KY')
            ->whereNull('parent_account_id')
            ->where('is_system_account', false)
            ->orderBy('account_name')
            ->get();

        // Esclude le 167 correzioni tecniche di apertura ledger (TRX-OPEN, stessa data
        // 2026-06-17): altrimenti riempirebbero da sole le "ultime 20" nascondendo le
        // emissioni reali. Restano consultabili nel backoffice tramite il filtro dedicato
        // "Correzioni tecniche" nella pagina Movimenti.
        $recentEmissions = Transfer::query()
            ->excludeLedgerCorrections()
            ->with(['toAccount.company:id,name', 'toAccount.ownerUser:id,name', 'initiator:id,name'])
            ->where('from_account_id', $systemAccount->id)
            ->where('status', 'booked')
            ->orderByDesc('booked_at')
            ->limit(20)
            ->get();

        return view('admin.emit-ky', [
            'pageTitle'              => 'Emissione KY — Cassa Circuito',
            'systemAccount'          => $systemAccount,
            'targetAccounts'         => $targetAccounts,
            'recentEmissions'        => $recentEmissions,
            // metriche circuito
            'kyInCirculation'        => $kyInCirculation,
            'circuitDelta'           => $circuitDelta,
            'circuitIsHealthy'       => $circuitIsHealthy,
            // flussi
            'totalOutFromSystem'     => $totalOutFromSystem,
            'totalReturnedToSystem'  => $totalReturnedToSystem,
            'implicitBalance'        => $implicitBalance,
            'hasImplicitBalance'     => $hasImplicitBalance,
            // breakdown
            'emissionBreakdown'      => $emissionBreakdown,
            'returnBreakdown'        => $returnBreakdown,
            // riserva
            'mainReserveAccount'     => $mainReserveAccount,
            'kyOnMainReserve'        => $kyOnMainReserve,
            'kyOnOtherAccounts'      => $kyOnOtherAccounts,
            // fidi
            'activeCreditLimitsTotal'=> $activeCreditLimitsTotal,
            // conti
            'accountsPositive'       => $accountsPositive,
            'accountsNegative'       => $accountsNegative,
            'activeNav'              => 'emit',
        ]);
    }

    /**
     * Esegue l'emissione KY: debita la Cassa Circuito e accredita il destinatario.
     */
    public function emitKy(Request $request, TransferBookingService $bookingService): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403, 'Solo il super admin puo emettere KY.');

        $systemAccount = Account::systemAccount();
        abort_unless($systemAccount !== null, 500, 'Conto Cassa Circuito non trovato.');

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount'        => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'description'   => ['nullable', 'string', 'max:500'],
        ]);

        $amountCents = ky_to_cents($validated['amount']);

        $toAccount = Account::query()->with(['company', 'ownerUser'])->findOrFail($validated['to_account_id']);

        abort_if($toAccount->is_system_account, 422, 'Non e possibile emettere KY verso la Cassa Circuito stessa.');
        abort_unless($toAccount->currency_code === 'KY', 422, 'Il conto destinatario deve essere un conto KY.');

        $description = $validated['description'] ?: 'Emissione KY -- Cassa Circuito KMoney';

        try {
            $transfer = $bookingService->book([
                'initiated_by'    => $request->user()->id,
                'from_account_id' => $systemAccount->id,
                'to_account_id'   => $toAccount->id,
                'amount'          => $amountCents,
                'description'     => $description,
                'kind'            => 'ky_emission',
                'idempotency_key' => (string) Str::uuid(),
                'ip_address'      => $request->ip(),
            ]);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('portal_error', 'Errore emissione: ' . $exception->getMessage());
        }

        AuditLog::create([
            'actor_user_id'   => $request->user()->id,
            'event'           => 'admin.ky.emitted',
            'auditable_type'  => Transfer::class,
            'auditable_id'    => $transfer->id,
            'ip_address'      => $request->ip(),
            'context'         => [
                'to_account_id'   => $toAccount->id,
                'to_account_name' => $toAccount->display_name,
                'amount'          => $amountCents,
                'description'     => $description,
            ],
        ]);

        $recipientName = $toAccount->display_name;

        return redirect()->route('admin.ky.emit')
            ->with('portal_success', 'Emessi ' . ky_format($amountCents) . ' KY su "' . $recipientName . '" correttamente.');
    }
}
