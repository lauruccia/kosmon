<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transfer;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReceiptController extends Controller
{
    /**
     * Scarica la ricevuta PDF di un singolo trasferimento.
     * Solo mittente, destinatario o admin possono scaricarla.
     */
    public function download(Request $request, string $uuid): Response
    {
        $user     = $request->user();
        $transfer = Transfer::with([
            'fromAccount.company',
            'fromAccount.ownerUser',
            'toAccount.company',
            'toAccount.ownerUser',
        ])->where('uuid', $uuid)->firstOrFail();

        $isAdmin = $user->canAccessBackoffice();

        if ($isAdmin) {
            // Admin: mostra il trasferimento dal punto di vista del mittente
            $account    = $transfer->fromAccount;
            $isOutgoing = true;
        } else {
            $account = $this->resolveAccount($user);

            abort_unless(
                $transfer->from_account_id === $account->id
                || $transfer->to_account_id === $account->id,
                403
            );

            $isOutgoing = $transfer->from_account_id === $account->id;
        }

        $counterparty = $isOutgoing ? $transfer->toAccount : $transfer->fromAccount;

        $pdf = Pdf::loadView('pdf.receipt', [
            'transfer'     => $transfer,
            'account'      => $account,
            'isOutgoing'   => $isOutgoing,
            'counterparty' => $counterparty,
            'generatedAt'  => now(),
        ])->setPaper('a4', 'portrait');

        $filename = 'ricevuta-' . $transfer->reference . '.pdf';

        return $pdf->download($filename);
    }

    private function resolveAccount(User $user): Account
    {
        if ($user->managed_account_id !== null) {
            $account = Account::with(['company', 'ownerUser'])
                ->findOrFail($user->managed_account_id);
            return $account->parentAccount ?? $account;
        }

        if ($user->company_id !== null) {
            return Account::with(['company', 'ownerUser'])
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->orderBy('id')
                ->firstOrFail();
        }

        return Account::with(['company', 'ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->firstOrFail();
    }
}
