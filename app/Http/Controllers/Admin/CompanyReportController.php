<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\CompanyReport;
use App\Services\CompanyReportService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Pannello admin "Segnalazioni aziende" (feature richiesta da Laura il
 * 29/07/2026, vedi CompanyReportService).
 *
 * AGGIORNAMENTO 30/07/2026 (decisione esplicita di Laura): la decisione di
 * segnare "contratto firmato" (eroga il bonus KY al segnalante) o "non
 * riuscita" NON spetta più all'agente assegnato ma SOLO all'admin — vedi
 * sign()/reject() qui sotto. L'agente resta in sola visibilità/copia della
 * segnalazione (vedi portal/mlm/company-reports.blade.php, ormai read-only),
 * simmetrico a come prima era l'admin.
 */
class CompanyReportController extends Controller
{
    use AuthorizesBackoffice;

    /** GET /admin/segnalazioni-aziende */
    public function index(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $query = CompanyReport::with(['reporter:id,name,email', 'agent:id,name,email', 'actionedBy:id,name']);

        $status = $request->query('status');
        if (in_array($status, [CompanyReport::STATUS_PENDING, CompanyReport::STATUS_CONTRACT_SIGNED, CompanyReport::STATUS_REJECTED], true)) {
            $query->where('status', $status);
        }

        $reports = $query->latest()->paginate(30)->withQueryString();

        return view('admin.mlm.company-reports', [
            'pageTitle'    => 'Segnalazioni aziende',
            'activeNav'    => 'admin-company-reports',
            'reports'      => $reports,
            'statusFilter' => $status,
            'pendingCount' => CompanyReport::pending()->count(),
        ]);
    }

    /** POST /admin/segnalazioni-aziende/{companyReport}/contratto-firmato */
    public function sign(Request $request, CompanyReport $companyReport, CompanyReportService $service): RedirectResponse
    {
        $admin = $request->user();
        $this->authorizeBackoffice($admin);

        abort_unless($companyReport->isPending(), 422, 'Segnalazione non più in attesa.');

        $validated = $request->validate([
            'agent_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->markContractSigned($companyReport, $admin, $validated['agent_notes'] ?? null);

        return back()->with('status', 'Contratto firmato registrato: bonus accreditato al cliente segnalante.');
    }

    /** POST /admin/segnalazioni-aziende/{companyReport}/rifiuta */
    public function reject(Request $request, CompanyReport $companyReport, CompanyReportService $service): RedirectResponse
    {
        $admin = $request->user();
        $this->authorizeBackoffice($admin);

        abort_unless($companyReport->isPending(), 422, 'Segnalazione non più in attesa.');

        $validated = $request->validate([
            'agent_notes' => ['required', 'string', 'max:1000'],
        ], [
            'agent_notes.required' => 'Inserisci una breve motivazione per il rifiuto.',
        ]);

        $service->markRejected($companyReport, $admin, $validated['agent_notes']);

        return back()->with('status', 'Segnalazione chiusa come non riuscita.');
    }
}
