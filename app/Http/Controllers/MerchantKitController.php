<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class MerchantKitController extends Controller
{
    /**
     * GET /portale/kit-merchant
     * Pagina hub con tutti gli strumenti di accettazione pagamenti.
     */
    public function index(Request $request): View
    {
        $user    = $request->user();
        $account = $this->resolveAccount($user);

        $kyNumber  = $account->account_number ?? '';
        $qrPayUrl  = $kyNumber ? route('portal.pay.qr', $kyNumber) : '';
        $whatsappText = urlencode('Pagami in KMoney: ' . $qrPayUrl);

        return view('portal.merchant-kit', [
            'pageTitle'     => 'Kit merchant — Strumenti di accettazione',
            'currentAccount' => $account,
            'currentUser'   => $user,
            'kyNumber'      => $kyNumber,
            'qrPayUrl'      => $qrPayUrl,
            'whatsappText'  => $whatsappText,
            'activeNav'     => 'incassa',
        ]);
    }

    /**
     * GET /portale/kit-merchant/qr-pdf
     * Scarica il PDF stampabile con QR grande per esporre in negozio.
     */
    public function qrPdf(Request $request): Response
    {
        $user    = $request->user();
        $account = $this->resolveAccount($user);

        $kyNumber = $account->account_number ?? '';
        $qrPayUrl = $kyNumber ? route('portal.pay.qr', $kyNumber) : '';
        $companyName = $account->company?->name ?? $user->name;

        $pdf = Pdf::loadView('pdf.merchant-qr', [
            'kyNumber'    => $kyNumber,
            'qrPayUrl'    => $qrPayUrl,
            'companyName' => $companyName,
            'qrSvg'       => $kyNumber
                ? \SimpleSoftwareIO\QrCode\Facades\QrCode::size(280)->errorCorrection('H')->generate($qrPayUrl)
                : '',
        ])->setPaper('a5', 'portrait');

        return $pdf->download("kit-merchant-{$kyNumber}.pdf");
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveAccount($user): Account
    {
        if ($user->managed_account_id) {
            return Account::with(['company', 'ownerUser'])->findOrFail($user->managed_account_id);
        }
        if ($user->company_id) {
            return Account::with('company')->where('company_id', $user->company_id)->whereNull('parent_account_id')->firstOrFail();
        }
        return Account::with('ownerUser')->where('owner_user_id', $user->id)->whereNull('parent_account_id')->firstOrFail();
    }
}
