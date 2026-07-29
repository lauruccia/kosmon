<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\CompanyReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pannello admin "Segnalazioni aziende" (feature richiesta da Laura il
 * 29/07/2026, vedi CompanyReportService): elenco READ-ONLY di TUTTE le
 * segnalazioni di tutti gli agenti — l'admin resta sempre e solo in
 * copia/visibilita', mai un gate di approvazione (la decisione di
 * segnare "contratto firmato" o "non riuscita" spetta solo all'agente
 * assegnato, vedi MlmPortalController::companyReportSign()/
 * companyReportReject()).
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
}
