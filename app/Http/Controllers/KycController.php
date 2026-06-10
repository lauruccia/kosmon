<?php

namespace App\Http\Controllers;

use App\Mail\KycStatusChanged;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\KycDocument;
use App\Models\SystemSetting;
use App\Services\TransferBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class KycController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    // PORTALE — lato azienda
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Pagina principale KYC dell'azienda: stato corrente + form upload.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->company_id) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Funzione disponibile solo per utenti aziendali.');
        }

        $company = Company::query()
            ->with(['kycDocuments.uploadedByUser'])
            ->findOrFail($user->company_id);

        return view('portal.kyc', [
            'pageTitle'    => 'Verifica aziendale KYC',
            'company'      => $company,
            'documents'    => $company->kycDocuments,
            'docTypes'     => KycDocument::TYPES,
            'kycStatuses'  => Company::KYC_STATUSES,
            'currentUser'  => $user,
            'activeNav'    => 'kyc',
        ]);
    }

    /**
     * Carica un nuovo documento KYC.
     */
    public function upload(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->company_id) {
            abort(403);
        }

        $company = Company::findOrFail($user->company_id);

        if ($company->kyc_status === 'approved') {
            return back()->with('portal_error', 'La verifica è già stata completata con successo.');
        }

        $validated = $request->validate([
            'type'     => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(KycDocument::TYPES))],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'], // 10 MB
        ]);

        $file          = $request->file('document');
        $storagePath   = $file->store("kyc/{$company->uuid}", 'private');

        KycDocument::create([
            'company_id'          => $company->id,
            'uploaded_by_user_id' => $user->id,
            'type'                => $validated['type'],
            'file_path'           => $storagePath,
            'original_name'       => $file->getClientOriginalName(),
            'mime_type'           => $file->getMimeType(),
            'file_size'           => $file->getSize(),
            'status'              => 'pending',
        ]);

        // Passa a "under_review" se era ancora "pending" (primo caricamento)
        if ($company->kyc_status === 'pending') {
            $company->update(['kyc_status' => 'under_review']);

            // Notifica il referente via email
            $this->notifyCompanyUsers($company, 'under_review');
        }

        return back()->with('portal_success', 'Documento caricato correttamente. Il nostro team lo esaminerà a breve.');
    }

    /**
     * Download sicuro di un documento (solo per l'azienda proprietaria o admin).
     */
    public function download(Request $request, KycDocument $kycDocument): Response|RedirectResponse
    {
        $user = $request->user();

        $canAccess = $user->is_super_admin
            || $user->canAccessBackoffice()
            || $user->company_id === $kycDocument->company_id;

        abort_unless($canAccess, 403);

        if (! Storage::disk('private')->exists($kycDocument->file_path)) {
            return back()->with('portal_error', 'File non trovato.');
        }

        return response()->download(
            Storage::disk('private')->path($kycDocument->file_path),
            $kycDocument->original_name,
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ADMIN — revisione KYC
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Lista aziende da verificare (admin).
     */
    public function adminIndex(Request $request): View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $status = (string) $request->query('status', 'under_review');
        $q      = trim((string) $request->query('q', ''));

        $companies = Company::query()
            ->withCount(['kycDocuments', 'kycDocuments as pending_docs_count' => fn ($q) => $q->where('status', 'pending')])
            ->when($status !== '', fn ($query) => $query->where('kyc_status', $status))
            ->when($q !== '', fn ($query) => $query->where(function ($scope) use ($q) {
                $scope->where('name', 'like', "%{$q}%")->orWhere('vat_number', 'like', "%{$q}%");
            }))
            ->orderByRaw("CASE kyc_status WHEN 'under_review' THEN 0 WHEN 'pending' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->paginate(20)->withQueryString();

        return view('admin.kyc-index', [
            'pageTitle'   => 'Verifica KYC aziende',
            'companies'   => $companies,
            'kycStatuses' => Company::KYC_STATUSES,
            'selectedStatus' => $status,
            'searchQuery' => $q,
            'activeNav'   => 'kyc',
        ]);
    }

    /**
     * Dettaglio KYC di una singola azienda (admin).
     */
    public function adminShow(Request $request, Company $company): View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $company->load(['kycDocuments.uploadedByUser', 'kycDocuments.reviewedByUser', 'kycReviewedBy', 'users']);

        return view('admin.kyc-show', [
            'pageTitle'   => 'KYC — ' . $company->name,
            'company'     => $company,
            'documents'   => $company->kycDocuments,
            'docTypes'    => KycDocument::TYPES,
            'kycStatuses' => Company::KYC_STATUSES,
            'activeNav'   => 'kyc',
        ]);
    }

    /**
     * Approva la verifica KYC.
     */
    public function approve(Request $request, Company $company): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $company->update([
            'kyc_status'      => 'approved',
            'kyc_notes'       => $request->input('notes'),
            'kyc_reviewed_by' => $request->user()->id,
            'kyc_reviewed_at' => CarbonImmutable::now(),
            'status'          => 'active',          // attiva anche il profilo aziendale
            'approved_at'     => CarbonImmutable::now(),
        ]);

        // Segna tutti i documenti pending come accepted
        $company->kycDocuments()->where('status', 'pending')->update([
            'status'               => 'accepted',
            'reviewed_by_user_id'  => $request->user()->id,
            'reviewed_at'          => CarbonImmutable::now(),
        ]);

        $this->notifyCompanyUsers($company, 'approved', $request->input('notes'));

        // ── Bonus benvenuto (fire-and-forget) ─────────────────────────────
        // Eroga un bonus KY al nuovo iscritto al momento dell'approvazione KYC.
        // L'importo è configurabile in SystemSetting.welcome_bonus_amount.
        // Se non configurato o zero, nessun bonus viene erogato.
        $this->maybeErogateWelcomeBonus($company, $request->user());

        return redirect()->route('admin.kyc.index')
            ->with('portal_success', "KYC approvato per {$company->name}.");
    }

    /**
     * Rifiuta la verifica KYC.
     */
    public function reject(Request $request, Company $company): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate(['notes' => ['required', 'string', 'min:10', 'max:1000']]);

        $company->update([
            'kyc_status'      => 'rejected',
            'kyc_notes'       => $request->input('notes'),
            'kyc_reviewed_by' => $request->user()->id,
            'kyc_reviewed_at' => CarbonImmutable::now(),
        ]);

        // Segna i documenti pending come rifiutati con la stessa nota
        $company->kycDocuments()->where('status', 'pending')->update([
            'status'               => 'rejected',
            'admin_notes'          => $request->input('notes'),
            'reviewed_by_user_id'  => $request->user()->id,
            'reviewed_at'          => CarbonImmutable::now(),
        ]);

        $this->notifyCompanyUsers($company, 'rejected', $request->input('notes'));

        return redirect()->route('admin.kyc.show', $company)
            ->with('portal_success', 'Verifica rifiutata. L\'azienda è stata notificata.');
    }

    /**
     * Richiede ulteriori documenti (riporta a under_review + notifica).
     */
    public function requestMoreDocs(Request $request, Company $company): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate(['notes' => ['required', 'string', 'min:10', 'max:1000']]);

        $company->update([
            'kyc_status' => 'under_review',
            'kyc_notes'  => $request->input('notes'),
        ]);

        $this->notifyCompanyUsers($company, 'under_review', $request->input('notes'));

        return back()->with('portal_success', 'Richiesta di documenti aggiuntivi inviata.');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Invia la mail di notifica KYC a tutti gli utenti dell'azienda.
     */
    private function notifyCompanyUsers(Company $company, string $newStatus, ?string $notes = null): void
    {
        $users = $company->users()->where('is_active', true)->get();

        foreach ($users as $user) {
            if ($user->email) {
                Mail::to($user->email)->queue(
                    new KycStatusChanged(
                        recipient: $user,
                        company: $company,
                        newStatus: $newStatus,
                        adminNotes: $notes,
                    )
                );
            }
        }
    }

    /**
     * Eroga il bonus benvenuto al conto della company appena approvata.
     * L'importo viene letto da SystemSetting (welcome_bonus_amount, in centesimi).
     * Se zero o non configurato, non fa nulla.
     * Usa una idempotency_key basata sull'ID company per evitare doppi bonus.
     */
    private function maybeErogateWelcomeBonus(Company $company, \App\Models\User $adminUser): void
    {
        try {
            $settings    = SystemSetting::userLimitDefaults();
            $bonusAmount = (int) ($settings->welcome_bonus_amount ?? 0);

            if ($bonusAmount <= 0) {
                return; // Bonus non configurato
            }

            $companyAccount = Account::where('company_id', $company->id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->first();

            if (! $companyAccount) {
                return;
            }

            $systemAccount = Account::systemAccount();
            if (! $systemAccount) {
                return;
            }

            $idempotencyKey = 'welcome_bonus_' . $company->id;

            // Verifica idempotency: bonus già erogato?
            if (\App\Models\Transfer::where('idempotency_key', $idempotencyKey)->exists()) {
                return;
            }

            $booking = app(TransferBookingService::class);
            $booking->book([
                'initiated_by'    => $adminUser->id,
                'from_account_id' => $systemAccount->id,
                'to_account_id'   => $companyAccount->id,
                'amount'          => $bonusAmount,
                'description'     => 'Bonus di benvenuto KMoney',
                'kind'            => 'portal_cashback', // usa cashback per essere esente da fee
                'idempotency_key' => $idempotencyKey,
            ]);

            AuditLog::create([
                'actor_user_id'  => $adminUser->id,
                'event'          => 'welcome_bonus.credited',
                'auditable_type' => Company::class,
                'auditable_id'   => $company->id,
                'context'        => [
                    'amount'     => $bonusAmount,
                    'account_id' => $companyAccount->id,
                ],
            ]);
        } catch (\Throwable $e) {
            // Non bloccare l'approvazione KYC se il bonus fallisce
            \Log::warning('Welcome bonus failed for company ' . $company->id . ': ' . $e->getMessage());
        }
    }
}
