<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class ContractController extends Controller
{
    // ── Impostazioni contratto di adesione ───────────────────────────────────

    public function contractTextUpdate(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate([
            'contract_text' => ['nullable', 'string', 'max:100000'],
        ]);

        $settings = \App\Models\SystemSetting::contractSettings();
        $settings->update([
            'contract_text'    => $request->input('contract_text'),
            'contract_version' => ($settings->contract_version ?? 1) + 1,
        ]);

        \App\Models\AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.contract_text.update',
            'auditable_type' => \App\Models\SystemSetting::class,
            'auditable_id'   => $settings->id,
            'context'        => ['version' => $settings->contract_version],
        ]);

        return back()->with('success', 'Testo del contratto aggiornato (versione ' . $settings->contract_version . ').');
    }

    public function contractSettings(): \Illuminate\View\View
    {
        abort_unless(request()->user()->canAccessBackoffice(), 403);

        // Handle default_text reset request
        if (request()->has('default_text')) {
            \App\Models\SystemSetting::contractSettings()->update([
                'contract_text'    => null,
                'contract_version' => 1,
            ]);
            return response()->json(['ok' => true]);
        }

        $settings        = \App\Models\SystemSetting::contractSettings();
        $forceSign       = (bool) $settings->contract_force_sign;
        $requiredFrom    = $settings->contract_required_from;
        $contractText    = $settings->contract_text ?? \App\Models\SystemSetting::defaultContractText();
        $contractVersion = $settings->contract_version ?? 1;
        $signedCount     = \App\Models\User::whereNotNull('contract_signed_at')->count();
        $totalUsers      = \App\Models\User::whereNotNull('company_id')->count();

        return view('admin.contract-settings', compact(
            'forceSign', 'requiredFrom', 'contractText', 'contractVersion', 'signedCount', 'totalUsers'
        ));
    }

    public function contractSettingsUpdate(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        $validated = $request->validate([
            'contract_force_sign'    => ['nullable', 'boolean'],
            'contract_required_from' => ['required', 'date_format:Y-m-d'],
        ]);

        \App\Models\SystemSetting::contractSettings()->update([
            'contract_force_sign'    => $request->boolean('contract_force_sign'),
            'contract_required_from' => $validated['contract_required_from'],
        ]);

        \App\Models\AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.contract_settings.update',
            'auditable_type' => \App\Models\SystemSetting::class,
            'auditable_id'   => 0,
            'context'        => [
                'force_sign'    => $request->boolean('contract_force_sign'),
                'required_from' => $validated['contract_required_from'],
            ],
        ]);

        return back()->with('success', 'Impostazioni contratto aggiornate.');
    }

    // ── Log firme contratto ───────────────────────────────────────────────────

    public function contractSignatures(\Illuminate\Http\Request $request): \Illuminate\View\View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $query = \App\Models\ContractSignature::with(['user', 'company'])
            ->latest('signed_at');

        if ($q = $request->input('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->whereHas('company', fn($c) => $c->where('name', 'like', "%{$q}%")
                        ->orWhere('vat_number', 'like', "%{$q}%"))
                    ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%"));
            });
        }

        if ($version = $request->input('version')) {
            $query->where('contract_version', $version);
        }

        if ($from = $request->input('from')) {
            $query->whereDate('signed_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('signed_at', '<=', $to);
        }

        $signatures = $query->paginate(30);
        $versions   = \App\Models\ContractSignature::distinct()->orderBy('contract_version')->pluck('contract_version');

        return view('admin.contract-signatures', compact('signatures', 'versions'));
    }

    public function contractSignatureShow(\App\Models\ContractSignature $signature): \Illuminate\View\View
    {
        abort_unless(request()->user()->canAccessBackoffice(), 403);
        $signature->load(['user', 'company']);
        return view('admin.contract-signature-show', compact('signature'));
    }

    public function contractSignaturesExport(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(request()->user()->canAccessBackoffice(), 403);

        $signatures = \App\Models\ContractSignature::with(['user', 'company'])
            ->latest('signed_at')->get();

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="firme-contratto-' . now()->format('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () use ($signatures) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
            fputcsv($handle, ['ID', 'Azienda', 'P.IVA', 'Utente', 'Email', 'Data Firma', 'Versione', 'IP', 'User Agent']);
            foreach ($signatures as $sig) {
                fputcsv($handle, [
                    $sig->id,
                    $sig->company?->name ?? '',
                    $sig->company?->vat_number ?? '',
                    $sig->user?->name ?? '',
                    $sig->user?->email ?? '',
                    $sig->signed_at->format('d/m/Y H:i:s'),
                    $sig->contract_version,
                    $sig->ip_address ?? '',
                    $sig->user_agent ?? '',
                ]);
            }
            fclose($handle);
        }, 200, $headers);
    }

    public function contractSignatureExportSingle(\App\Models\ContractSignature $signature): \Illuminate\Http\Response
    {
        abort_unless(request()->user()->canAccessBackoffice(), 403);
        $signature->load(['user', 'company']);

        $companyName = $signature->company?->name ?? $signature->user?->name ?? 'Utente';
        $html = '<html><head><meta charset="UTF-8">
<style>
body{font-family:Georgia,serif;font-size:13px;line-height:1.7;color:#111;margin:40px 50px;}
h1{font-size:1.2rem;color:#0f766e;margin-bottom:4px;}
.meta{font-size:11px;color:#666;margin-bottom:32px;border-bottom:1px solid #ddd;padding-bottom:16px;}
h2{font-size:.95rem;font-weight:700;margin:20px 0 8px;color:#0f766e;}
p{margin:0 0 12px;}
hr{border:none;border-top:1px solid #ddd;margin:20px 0;}
ul,ol{padding-left:20px;}
li{margin-bottom:6px;}
.footer{margin-top:40px;border-top:2px solid #0f766e;padding-top:16px;font-size:11px;color:#555;}
</style></head><body>
<h1>Contratto di Adesione al Circuito KMoney &mdash; v' . $signature->contract_version . '</h1>
<div class="meta">
<strong>Azienda:</strong> ' . e($companyName) . ' &nbsp;|&nbsp;
<strong>Firmato il:</strong> ' . $signature->signed_at->format('d/m/Y \\l\\l\\e H:i:s') . ' &nbsp;|&nbsp;
<strong>IP:</strong> ' . e($signature->ip_address ?? 'n.d.') . ' &nbsp;|&nbsp;
<strong>Utente:</strong> ' . e($signature->user?->name ?? '') . ' (' . e($signature->user?->email ?? '') . ')
</div>
' . $signature->contract_html_snapshot . '
<div class="footer">
Documento generato da KMoney &mdash; Firma digitale con OTP via email<br>
Codice firma: ' . strtoupper(substr(md5($signature->id . $signature->signed_at), 0, 12)) . '
</div>
</body></html>';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4');
        $filename = 'contratto-' . \Illuminate\Support\Str::slug($companyName) . '-' . $signature->signed_at->format('Ymd') . '.pdf';
        return $pdf->download($filename);
    }
}
