<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateContractSettingsRequest;
use App\Http\Requests\UpdateContractTextRequest;

class ContractController extends Controller
{
    // ── Impostazioni contratto di adesione ───────────────────────────────────

    public function contractTextUpdate(UpdateContractTextRequest $request): \Illuminate\Http\RedirectResponse
    {

        $settings = \App\Models\SystemSetting::contractSettings();

        // DUE COSE DIVERSE, TRATTATE IN MODO OPPOSTO (03/09/2026, regola di
        // Laura). Salvare il testo non e' un gesto unico:
        //
        //   'correzione' — refuso, punteggiatura, un riferimento sbagliato.
        //     Non cambia cosa e' stato pattuito: la VERSIONE NON SALE e le
        //     aziende che hanno firmato questa stessa versione vedono da
        //     subito il testo corretto al posto dell'errore (vedi
        //     SystemSetting::correctionAppliesTo() e ContractController::
        //     viewSigned()). Nessuna firma da rifare.
        //
        //   'revisione' — si aggiungono o si cambiano condizioni. La versione
        //     sale e il testo nuovo vale per chi firma da adesso; chi ha gia'
        //     firmato non viene interpellato e continua a vedere la SUA
        //     versione, perche' e' quella che ha accettato.
        //
        //   'revisione_rifirma' — come sopra, ma le aziende rimaste sotto la
        //     nuova versione vengono riportate alla firma.
        //
        // Il default e' 'revisione': e' esattamente il comportamento che
        // questa pagina aveva prima, quindi una richiesta senza il campo non
        // cambia niente di nascosto.
        $modo = in_array($request->input('save_mode'), ['correzione', 'revisione', 'revisione_rifirma'], true)
            ? $request->input('save_mode')
            : 'revisione';

        $versioneAttuale = (int) ($settings->contract_version ?? 1);
        $nuovaVersione   = $modo === 'correzione' ? $versioneAttuale : $versioneAttuale + 1;

        $dati = [
            'contract_text'    => sanitize_html($request->input('contract_text')),
            'contract_version' => $nuovaVersione,
        ];

        if ($modo === 'correzione') {
            // La data serve a dire all'azienda, sulla sua pagina, perche' il
            // testo non e' piu' identico a quello che ha firmato.
            $dati['contract_text_corrected_at'] = now();
        } else {
            // Versione nuova: parte pulita, nessuna correzione da segnalare.
            $dati['contract_text_corrected_at'] = null;
        }

        // La soglia della rifirma si tocca SOLO quando e' chiesto: una
        // correzione o una revisione semplice non devono ne' aprire ne'
        // chiudere una rifirma gia' in corso.
        if ($modo === 'revisione_rifirma') {
            $dati['contract_resign_from_version'] = $nuovaVersione;
        }

        $daRifirmare = $modo === 'revisione_rifirma' ? $this->resignPendingCount($nuovaVersione) : 0;

        $settings->update($dati);

        \App\Models\AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.contract_text.update',
            'auditable_type' => \App\Models\SystemSetting::class,
            'auditable_id'   => $settings->id,
            'context'        => [
                'modo'                 => $modo,
                'version'              => $settings->contract_version,
                'aziende_da_rifirmare' => $daRifirmare,
            ],
        ]);

        $msg = match ($modo) {
            'correzione' => 'Correzione salvata. La versione resta la ' . $nuovaVersione
                . ': le aziende che hanno firmato questa versione vedono da subito il testo corretto,'
                . ' e non devono rifirmare.',
            'revisione_rifirma' => 'Pubblicata la versione ' . $nuovaVersione . '. '
                . $daRifirmare . ' aziende verranno riportate alla firma al prossimo accesso.',
            default => 'Pubblicata la versione ' . $nuovaVersione
                . '. Vale per chi firma da adesso; chi ha gia\' firmato non viene interpellato'
                . ' e continua a vedere la propria versione.',
        };

        return back()->with('success', $msg);
    }

    /**
     * Quante aziende hanno firmato una versione precedente alla soglia, e
     * quindi si troveranno la pagina di firma davanti. Serve a dirlo PRIMA
     * di premere il bottone, non a cose fatte.
     */
    private function resignPendingCount(int $soglia): int
    {
        if ($soglia <= 0) {
            return 0;
        }

        return \App\Models\User::query()
            ->where('is_super_admin', false)
            ->whereNotNull('company_id')
            ->whereNotNull('contract_signed_at')
            ->whereRaw('COALESCE(contract_signed_version, 1) < ?', [$soglia])
            ->count();
    }

    /**
     * Annulla una richiesta di rifirma partita per errore: riporta la soglia
     * a zero e nessuno viene piu' interpellato. Le firme gia' raccolte sulla
     * nuova versione restano, ovviamente: sono firme vere.
     */
    public function cancelResign(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $settings = \App\Models\SystemSetting::contractSettings();
        $prima    = (int) ($settings->contract_resign_from_version ?? 0);

        $settings->update(['contract_resign_from_version' => 0]);

        \App\Models\AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.contract_resign.cancel',
            'auditable_type' => \App\Models\SystemSetting::class,
            'auditable_id'   => $settings->id,
            'context'        => ['soglia_annullata' => $prima],
        ]);

        return back()->with('success', 'Richiesta di rifirma annullata: nessuna azienda verra\' piu\' riportata alla firma.');
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

        if (request()->has('default_agent_text')) {
            \App\Models\SystemSetting::agentContractSettings()->update([
                'mlm_agent_contract_text'    => null,
                'mlm_agent_contract_version' => 1,
            ]);
            return response()->json(['ok' => true]);
        }

        if (request()->has('default_agent_directives_text')) {
            \App\Models\SystemSetting::agentContractSettings()->update([
                'mlm_agent_directives_text'    => null,
                'mlm_agent_directives_version' => 1,
            ]);
            return response()->json(['ok' => true]);
        }

        $settings             = \App\Models\SystemSetting::contractSettings();
        $forceSign            = (bool) $settings->contract_force_sign;
        $requiredFrom         = $settings->contract_required_from;
        $contractText         = $settings->contract_text ?? \App\Models\SystemSetting::defaultContractText();
        $contractVersion      = $settings->contract_version ?? 1;
        $resignFromVersion    = (int) ($settings->contract_resign_from_version ?? 0);
        $textCorrectedAt      = $settings->contract_text_corrected_at;
        $resignPendingCount   = $this->resignPendingCount($resignFromVersion);
        $signedCount          = \App\Models\User::whereNotNull('contract_signed_at')->count();
        $totalUsers           = \App\Models\User::whereNotNull('company_id')->count();

        $agentContractText    = $settings->mlm_agent_contract_text ?? \App\Models\SystemSetting::defaultAgentContractText();
        $agentContractVersion = $settings->mlm_agent_contract_version ?? 1;
        $agentSignedCount     = \App\Models\User::whereNotNull('mlm_agent_contract_signed_at')->count();
        $agentPendingCount    = \App\Models\User::where('mlm_agent_request_status', 'approved')
            ->whereNull('mlm_agent_contract_signed_at')->count();

        // 2026-08-07: "Direttive e Procedure Kosmos", secondo documento che
        // l'agente accetta con la stessa firma OTP del contratto — vedi
        // MlmAgentContractController e SystemSetting::defaultAgentDirectivesText().
        $agentDirectivesText    = $settings->mlm_agent_directives_text ?? \App\Models\SystemSetting::defaultAgentDirectivesText();
        $agentDirectivesVersion = $settings->mlm_agent_directives_version ?? 1;

        return view('admin.contract-settings', compact(
            'forceSign', 'requiredFrom', 'contractText', 'contractVersion', 'signedCount', 'totalUsers',
            'resignFromVersion', 'resignPendingCount', 'textCorrectedAt',
            'agentContractText', 'agentContractVersion', 'agentSignedCount', 'agentPendingCount',
            'agentDirectivesText', 'agentDirectivesVersion'
        ));
    }

    /** Aggiorna il testo del contratto di nomina ad Agente KNM (form separato, versionato). */
    public function agentContractTextUpdate(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate(['agent_contract_text' => ['required', 'string']]);

        $settings = \App\Models\SystemSetting::agentContractSettings();
        $settings->update([
            'mlm_agent_contract_text'    => sanitize_html($request->input('agent_contract_text')),
            'mlm_agent_contract_version' => ($settings->mlm_agent_contract_version ?? 1) + 1,
        ]);

        \App\Models\AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.mlm_agent_contract_text.update',
            'auditable_type' => \App\Models\SystemSetting::class,
            'auditable_id'   => $settings->id,
            'context'        => ['version' => $settings->mlm_agent_contract_version],
        ]);

        return back()->with('success', 'Testo del contratto agente aggiornato (versione ' . $settings->mlm_agent_contract_version . ').');
    }

    /** Aggiorna il testo delle Direttive e Procedure Kosmos accettate dall'agente (form separato, versionato). */
    public function agentDirectivesTextUpdate(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate(['agent_directives_text' => ['required', 'string']]);

        $settings = \App\Models\SystemSetting::agentContractSettings();
        $settings->update([
            'mlm_agent_directives_text'    => sanitize_html($request->input('agent_directives_text')),
            'mlm_agent_directives_version' => ($settings->mlm_agent_directives_version ?? 1) + 1,
        ]);

        \App\Models\AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.mlm_agent_directives_text.update',
            'auditable_type' => \App\Models\SystemSetting::class,
            'auditable_id'   => $settings->id,
            'context'        => ['version' => $settings->mlm_agent_directives_version],
        ]);

        return back()->with('success', 'Testo delle Direttive e Procedure Kosmos aggiornato (versione ' . $settings->mlm_agent_directives_version . ').');
    }

    public function contractSettingsUpdate(UpdateContractSettingsRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

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
' . sanitize_html($signature->contract_html_snapshot) . '
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
