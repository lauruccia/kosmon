<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Sector;
use App\Models\KycDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════

    // Step 0 — Benvenuto (prima schermata post-registrazione)
    public function step0(Request $request): View|RedirectResponse
    {
        $company = $this->getCompanyOrAbort($request);

        // Se gia' completato il profilo, salta al passo corrente
        if (!empty($company->sector) && !empty($company->description)) {
            return $this->redirectToCurrentStep($company);
        }

        return view('onboarding.step0', [
            'pageTitle' => 'Benvenuto in KMoney!',
            'company'   => $company,
        ]);
    }

    // Step finale — Account approvato, guida rapida
    public function completed(Request $request): View|RedirectResponse
    {
        $company = $this->getCompanyOrAbort($request);

        // Solo per aziende gia' approvate
        if ($company->kyc_status !== 'approved') {
            return $this->redirectToCurrentStep($company);
        }

        return view('onboarding.completed', [
            'pageTitle' => 'Pronto a partire!',
            'company'   => $company,
        ]);
    }

    // Step 1 — Profilo azienda
    // ═══════════════════════════════════════════════════════════════════════

    public function step1(Request $request): View|RedirectResponse
    {
        $company = $this->getCompanyOrAbort($request);

        // Se già completato, avanza allo step successivo
        if (! empty($company->sector) && ! empty($company->description)) {
            return $this->redirectToCurrentStep($company);
        }

        return view('onboarding.step1', [
            'pageTitle' => 'Benvenuto in KMoney — Completa il profilo',
            'company'   => $company,
            'sectors'   => $this->sectorOptions(),
        ]);
    }

    public function saveStep1(Request $request): RedirectResponse
    {
        $company = $this->getCompanyOrAbort($request);

        $validated = $request->validate([
            'sector'      => ['required', 'string', 'max:120', \Illuminate\Validation\Rule::in(Sector::activeList()->toArray())],
            'description' => ['required', 'string', 'min:20', 'max:500'],
            'website'     => ['nullable', 'url', 'max:255'],
            'phone'       => ['nullable', 'string', 'max:30'],
            'vat_number'  => ['nullable', 'string', 'max:50'],
            'fiscal_code' => ['nullable', 'string', 'max:50'],
        ]);

        $company->update($validated);

        return redirect()->route('onboarding.step2')
            ->with('onboarding_success', 'Profilo salvato! Ora carica i documenti di verifica.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Step 2 — Upload documenti KYC
    // ═══════════════════════════════════════════════════════════════════════

    public function step2(Request $request): View|RedirectResponse
    {
        $company = $this->getCompanyOrAbort($request);

        // Se il profilo non è completo, torna allo step 1
        if (empty($company->sector) || empty($company->description)) {
            return redirect()->route('onboarding.step1');
        }

        // Se già approvata, vai alla dashboard
        if ($company->kyc_status === 'approved') {
            return redirect()->route('portal.dashboard');
        }

        $company->load('kycDocuments');

        return view('onboarding.step2', [
            'pageTitle' => 'Benvenuto in KMoney — Documenti KYC',
            'company'   => $company,
            'documents' => $company->kycDocuments,
            'docTypes'  => KycDocument::TYPES,
        ]);
    }

    public function uploadKyc(Request $request): RedirectResponse
    {
        $company = $this->getCompanyOrAbort($request);
        $user    = $request->user();

        if ($company->kyc_status === 'approved') {
            return redirect()->route('portal.dashboard');
        }

        $validated = $request->validate([
            'type'     => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(KycDocument::TYPES))],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $file        = $request->file('document');
        $storagePath = $file->store("kyc/{$company->uuid}", 'private');

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

        // Prima volta: aggiorna lo stato KYC e notifica l'admin
        if ($company->kyc_status === 'pending') {
            $company->update(['kyc_status' => 'under_review']);
        }

        return redirect()->route('onboarding.step2')
            ->with('onboarding_success', 'Documento caricato correttamente.');
    }

    public function proceedToWaiting(Request $request): RedirectResponse
    {
        $company = $this->getCompanyOrAbort($request);
        $company->load('kycDocuments');

        if ($company->kycDocuments->isEmpty()) {
            return redirect()->route('onboarding.step2')
                ->with('onboarding_error', 'Carica almeno un documento prima di procedere.');
        }

        return redirect()->route('onboarding.step3');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Step 3 — In attesa di approvazione
    // ═══════════════════════════════════════════════════════════════════════

    public function step3(Request $request): View|RedirectResponse
    {
        $company = $this->getCompanyOrAbort($request);

        // Se approvata, entra nel portale
        if ($company->kyc_status === 'approved') {
            return redirect()->route('onboarding.completed');
        }

        // Se rifiutata o non ancora caricati i documenti, riporta allo step 2
        if ($company->kycDocuments()->count() === 0) {
            return redirect()->route('onboarding.step2');
        }

        $company->load('kycDocuments');

        return view('onboarding.step3', [
            'pageTitle' => 'Verifica in corso — KMoney',
            'company'   => $company,
            'documents' => $company->kycDocuments,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function getCompanyOrAbort(Request $request): Company
    {
        $user = $request->user();
        abort_unless($user && $user->company_id, 403);

        return Company::findOrFail($user->company_id);
    }

    private function redirectToCurrentStep(Company $company): RedirectResponse
    {
        if ($company->kyc_status === 'approved') {
            return redirect()->route('portal.dashboard');
        }

        if (empty($company->sector) || empty($company->description)) {
            return redirect()->route('onboarding.step1');
        }

        if ($company->kycDocuments()->count() === 0) {
            return redirect()->route('onboarding.step2');
        }

        return redirect()->route('onboarding.step3');
    }

    private function sectorOptions(): array
    {
        return Sector::selectableOptions();
    }
}
