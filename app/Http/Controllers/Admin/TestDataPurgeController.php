<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Company;
use App\Services\TestDataPurgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Cancellazione FISICA e completa di dati di prova/test: un'intera azienda (con
 * account, utenti, movimenti) o un singolo conto privato (KYP) con il suo utente.
 *
 * A differenza dello storno o della cancellazione del singolo movimento
 * (AdminController::destroyTransfer), qui sparisce ogni traccia dell'entità di test:
 * non solo i suoi movimenti, ma l'account, l'utente e tutti i record collegati
 * (KYC, contratti, credenziali, card NFC, shop, annunci...).
 *
 * Operazione irreversibile: richiede super admin + conferma esplicita (l'admin deve
 * digitare il nome esatto dell'azienda/conto prima che il bottone si attivi).
 */
class TestDataPurgeController extends Controller
{
    use AuthorizesBackoffice;

    public function __construct(private readonly TestDataPurgeService $purgeService)
    {
    }

    // ── Azienda ──────────────────────────────────────────────────────────────

    public function confirmCompany(Request $request, Company $company): View
    {
        $this->authorizeSuperAdmin($request);

        return view('admin.test-data-purge-confirm', [
            'pageTitle'   => 'Elimina definitivamente — ' . $company->name,
            'mode'        => 'company',
            'target'      => $company,
            'targetLabel' => $company->name,
            'preview'     => $this->purgeService->previewCompany($company),
            'submitRoute' => route('admin.companies.purge-test.destroy', $company),
            'cancelRoute' => route('admin.companies.show', $company),
            'activeNav'   => 'companies',
        ]);
    }

    public function purgeCompany(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $this->assertConfirmationMatches($request, $company->name);

        $force = (bool) $request->boolean('force');

        try {
            $result = DB::transaction(fn () => $this->purgeService->purgeCompany(
                $company,
                $request->user(),
                $request->ip(),
                $force
            ));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            // abort_if/abort_unless nel service: errore deliberato e già pulito
            // (es. soldi reali rilevati, super admin protetto) — lascialo propagare
            // con il suo status code originale, invece di trasformarlo in un redirect.
            throw $e;
        } catch (\Throwable $e) {
            Log::error('admin.test_data.purge_company_failed', [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);

            return back()->with(
                'portal_error',
                'Cancellazione annullata: ' . $e->getMessage()
            );
        }

        return redirect()->route('admin.companies.index')->with(
            'portal_success',
            "Azienda \"{$result['company']}\" eliminata completamente: {$result['accounts']} conti, "
                . "{$result['users']} utenti, {$result['transfers']} movimenti rimossi. Saldi ripristinati, circuito ribilanciato."
        );
    }

    // ── Conto privato (KYP) ─────────────────────────────────────────────────

    public function confirmAccount(Request $request, Account $account): View
    {
        $this->authorizeSuperAdmin($request);

        abort_if($account->is_system_account, 422, 'Il conto sistema non può essere eliminato.');
        abort_if($account->company_id !== null, 422, "Conto aziendale: elimina l'intera azienda dalla sua pagina.");

        $label = $account->ownerUser?->name ?? $account->account_number;

        return view('admin.test-data-purge-confirm', [
            'pageTitle'   => 'Elimina definitivamente — ' . $label,
            'mode'        => 'account',
            'target'      => $account,
            'targetLabel' => $label,
            'preview'     => $this->purgeService->previewAccount($account),
            'submitRoute' => route('admin.accounts.purge-test.destroy', $account),
            'cancelRoute' => route('admin.accounts.show', $account),
            'activeNav'   => 'accounts',
        ]);
    }

    public function purgeAccount(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);

        $label = $account->ownerUser?->name ?? $account->account_number;
        $this->assertConfirmationMatches($request, $label);

        $force = (bool) $request->boolean('force');

        try {
            $result = DB::transaction(fn () => $this->purgeService->purgeAccount(
                $account,
                $request->user(),
                $request->ip(),
                $force
            ));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('admin.test_data.purge_account_failed', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);

            return back()->with(
                'portal_error',
                'Cancellazione annullata: ' . $e->getMessage()
            );
        }

        return redirect()->route('admin.accounts.index')->with(
            'portal_success',
            "Conto eliminato completamente: {$result['users']} utente, {$result['transfers']} movimenti rimossi. "
                . 'Saldi ripristinati, circuito ribilanciato.'
        );
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    private function authorizeSuperAdmin(Request $request): void
    {
        $this->authorizeBackoffice($request->user());
        abort_unless($request->user()->is_super_admin, 403, 'Solo un super admin può eliminare definitivamente dati di test.');
    }

    private function assertConfirmationMatches(Request $request, string $expected): void
    {
        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
        ]);

        abort_unless(
            trim($validated['confirmation']) === trim($expected),
            422,
            'Conferma non corrispondente: digita esattamente il nome mostrato per procedere.'
        );
    }
}
