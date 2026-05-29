<?php

namespace App\Http\Controllers;

use App\Jobs\SendBroadcastMessageJob;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminBroadcastController extends Controller
{
    public function index(): View
    {
        return view('admin.broadcast.index', [
            'pageTitle' => 'Comunicazione massiva',
            'segments'  => $this->segmentOptions(),
        ]);
    }

    public function preview(Request $request)
    {
        $companies = $this->resolveSegment($request->segment ?? 'all');
        return response()->json([
            'count'   => $companies->count(),
            'preview' => $companies->take(5)->pluck('name'),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate([
            'segment'  => ['required', 'string'],
            'subject'  => ['required', 'string', 'max:200'],
            'body'     => ['required', 'string', 'max:5000'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['in:email,in_app'],
        ]);

        $companies = $this->resolveSegment($request->segment);
        $count     = $companies->count();

        if ($count === 0) {
            return back()->withErrors(['segment' => 'Nessuna azienda trovata per questo segmento.']);
        }

        dispatch(new SendBroadcastMessageJob(
            companyIds: $companies->pluck('id')->toArray(),
            subject:    $request->subject,
            body:       $request->body,
            channels:   $request->channels,
            senderId:   $request->user()->id,
        ));

        return redirect()->route('admin.broadcast.index')
            ->with('success', "Comunicazione avviata per {$count} aziende.");
    }

    private function resolveSegment(string $segment)
    {
        return match ($segment) {
            'all'           => Company::where('status', 'active')->get(),
            'kyc_approved'  => Company::where('status', 'active')->where('kyc_status', 'approved')->get(),
            'kyc_pending'   => Company::where('kyc_status', 'pending')->get(),
            'negative_balance' => Company::where('status', 'active')
                ->whereHas('accounts', fn ($q) => $q->where('available_balance', '<', 0)->whereNull('parent_account_id'))
                ->get(),
            'suspended'     => Company::whereNotNull('suspended_at')->get(),
            default         => Company::where('status', 'active')->get(),
        };
    }

    private function segmentOptions(): array
    {
        return [
            'all'              => 'Tutte le aziende attive',
            'kyc_approved'     => 'KYC approvato',
            'kyc_pending'      => 'KYC in attesa di verifica',
            'negative_balance' => 'Saldo negativo (in debito)',
            'suspended'        => 'Aziende sospese',
        ];
    }
}
