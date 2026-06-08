<?php

namespace App\Http\Controllers;

use App\Mail\PaymentReceived;
use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\PaymentReceivedNotification;
use App\Services\TransferBookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Events\PaymentRequestUpdated;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * PaymentRequestController
 *
 * Gestisce il lato pagatore delle PaymentRequest (QR dinamico).
 *
 *   GET  /pay/{token}  -> pagina di pagamento (auth required)
 *   POST /pay/{token}  -> esegue il pagamento
 */
class PaymentRequestController extends Controller
{
    /** Mostra la pagina di pagamento. */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard')
                ->with('portal_error', 'Gli amministratori non possono effettuare pagamenti dal portale.');
        }

        $pr = PaymentRequest::with(['toAccount.company', 'toAccount.ownerUser'])
            ->where('token', $token)
            ->firstOrFail();

        // Aggiorna scaduta on-the-fly
        if ($pr->status === 'pending' && $pr->expires_at->isPast()) {
            $pr->update(['status' => 'expired']);
            $pr->refresh();
        }

        $fromAccount = $this->resolveAccount($user);

        // Non puoi pagare te stesso
        if ($fromAccount->id === $pr->to_account_id) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Non puoi pagare il tuo stesso conto. (Conto pagatore ' . $fromAccount->account_number . ' = conto destinatario ' . $pr->toAccount?->account_number . ')');
        }

        return view('portal.pay-request', [
            'pageTitle'   => 'Richiesta di pagamento',
            'pr'          => $pr,
            'fromAccount' => $fromAccount,
            'activeNav'   => 'conto',
        ]);
    }

    /** Esegue il pagamento. */
    public function pay(
        Request $request,
        string $token,
        TransferBookingService $bookingService
    ): RedirectResponse {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $pr = PaymentRequest::with(['toAccount.company', 'toAccount.ownerUser'])
            ->where('token', $token)
            ->firstOrFail();

        // Verifica che il conto destinatario (merchant) sia ancora attivo al momento
        // del pagamento. Potrebbe essere stato sospeso dopo la creazione del QR.
        if ($pr->toAccount === null || $pr->toAccount->status !== 'active') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Il conto del destinatario non è più attivo. Pagamento annullato.');
        }

        // Validazioni stato
        if ($pr->isPaid()) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Questa richiesta di pagamento e\' gia\' stata saldata.');
        }

        if ($pr->isExpired()) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Questa richiesta di pagamento e\' scaduta. Chiedi un nuovo QR al commerciante.');
        }

        if ($pr->status === 'cancelled') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Questa richiesta di pagamento e\' stata annullata.');
        }

        $fromAccount = $this->resolveAccount($user);

        if ($fromAccount->id === $pr->to_account_id) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Non puoi pagare il tuo stesso conto. (Conto pagatore ' . $fromAccount->account_number . ' = conto destinatario ' . $pr->toAccount?->account_number . ')');
        }

        if ($fromAccount->status !== 'active') {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Il tuo conto non e\' attivo. Impossibile eseguire il pagamento.');
        }

        // Esegui il trasferimento
        try {
            $transfer = $bookingService->book([
                'initiated_by'    => $user->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id'   => $pr->to_account_id,
                'amount'          => $pr->amount,
                'description'     => $pr->description ?? 'Pagamento QR KMoney',
                'kind'            => 'portal_qr_payment',
                'idempotency_key' => 'pr_' . $pr->uuid,
                'ip_address'      => $request->ip(),
            ]);
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        // Segna la richiesta come pagata
        $pr->update([
            'status'          => 'paid',
            'paid_at'         => now(),
            'transfer_id'     => $transfer->id,
            'from_account_id' => $fromAccount->id,
        ]);

        // Broadcast real-time al merchant (aggiorna UI senza polling)
        broadcast(new PaymentRequestUpdated($pr->fresh()))->toOthers();

        // Notifica al commerciante (destinatario)
        $toAccount  = $pr->toAccount;
        $toOwner    = $toAccount?->ownerUser ?? $toAccount?->company?->users()->first();

        if ($toOwner) {
            Mail::to($toOwner->email)->queue(
                new PaymentReceived(
                    recipient:    $toOwner,
                    transfer:     $transfer,
                    fromAccount:  $fromAccount,
                    toAccount:    $toAccount,
                    balanceAfter: (int) $toAccount->fresh()->available_balance,
                )
            );
            $toOwner->notify(new PaymentReceivedNotification(
                transfer:    $transfer,
                fromAccount: $fromAccount,
                toAccount:   $toAccount,
            ));
        }

        return redirect()->route('portal.dashboard')
            ->with('portal_success', 'Pagamento di ' . ky_format($pr->amount) . ' KY eseguito con successo!');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveAccount(User $user): Account
    {
        if ($user->managed_account_id !== null) {
            $sub = Account::with(['company', 'ownerUser', 'parentAccount'])
                ->findOrFail($user->managed_account_id);
            return $sub->parentAccount ?? $sub;
        }

        if ($user->company_id !== null) {
            return Account::with(['company', 'ownerUser'])
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->orderBy('id')
                ->firstOrFail();
        }

        return Account::with(['company', 'ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->orderBy('id')
            ->firstOrFail();
    }
}
