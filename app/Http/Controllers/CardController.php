<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CardController extends Controller
{
    /**
     * Mostra la carta virtuale con QR dell'utente corrente.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveRootAccount($user);

        $payUrl = route('portal.pay.qr', $account->account_number);

        return view('portal.card', [
            'pageTitle' => 'La tua carta KMoney',
            'account'   => $account,
            'payUrl'    => $payUrl,
            'activeNav' => 'carta',
        ]);
    }

    /**
     * Pagamento rapido da QR — pre-compila destinatario dall'account number in URL.
     */
    public function payQr(Request $request, string $accountNumber): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $toAccount = Account::query()
            ->with(['company', 'ownerUser'])
            ->where('uuid', $accountNumber)
            ->where('status', 'active')
            ->firstOrFail();

        $fromAccount = $this->resolveRootAccount($user);

        // Non puoi pagare te stesso
        if ($fromAccount->id === $toAccount->id) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Non puoi pagare il tuo stesso conto tramite QR.');
        }

        return view('portal.pay-qr', [
            'pageTitle'   => 'Paga ' . ($toAccount->company?->name ?? $toAccount->display_name),
            'fromAccount' => $fromAccount,
            'toAccount'   => $toAccount,
            'activeNav'   => 'paga',
        ]);
    }

    /**
     * Blocca il conto (sospende la carta).
     */
    public function block(Request $request): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveRootAccount($user);

        $account->forceFill(['status' => 'suspended'])->save();

        return redirect()->route('portal.card')
            ->with('portal_success', 'Carta bloccata. Nessun pagamento potrà essere effettuato finché non la sblocchi.');
    }

    /**
     * Sblocca il conto.
     */
    public function unblock(Request $request): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveRootAccount($user);

        $account->forceFill(['status' => 'active'])->save();

        return redirect()->route('portal.card')
            ->with('portal_success', 'Carta sbloccata. Puoi di nuovo effettuare pagamenti.');
    }


    /**
     * Scarica la tessera KMoney formato PDF (85x54mm stampabile).
     */
    public function downloadPdf(Request $request): \Illuminate\Http\Response
    {
        $user    = $request->user();
        $account = $this->resolveRootAccount($user);
        $payUrl  = route('portal.pay.qr', $account->account_number);

        // Genera QR code come SVG (simplesoftwareio/simple-qrcode)
        $qrDataUri = null;
        if (class_exists(\SimpleSoftwareIO\QrCode\Generator::class)) {
            $gen       = new \SimpleSoftwareIO\QrCode\Generator();
            $svg       = (string) $gen->format('svg')->size(180)->generate($payUrl);
            $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('portal.card-pdf', [
            'account'    => $account,
            'payUrl'     => $payUrl,
            'qrDataUri' => $qrDataUri,
        ])->setPaper([0, 0, 241.89, 153.07], 'landscape');

        $filename = 'tessera-kmoney-' . $account->account_number . '.pdf';
        return $pdf->download($filename);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function resolveRootAccount(User $user): Account
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
