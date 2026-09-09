<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\StatementBuilder;
use App\Support\StatementPeriod;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Estratto conto: un periodo, tre formati, tre profili.
 *
 * PERCHE' UN SOLO PUNTO DI DOWNLOAD (09/09/2026). Il formato e' un parametro
 * (`?formato=pdf|csv|prima-nota`), non una rotta per formato. Cosi' le regole di
 * accesso — chi puo' scaricare l'estratto di quale conto — stanno scritte una
 * volta sola: aggiungere domani un formato non significa ricordarsi di
 * ricopiarci sopra i controlli di autorizzazione.
 *
 * IL PERIODO NON SI VALIDA, SI RISOLVE. Vedi StatementPeriod: qualunque
 * parametro arrivi, si ottiene un periodo sensato. Un estratto conto che
 * risponde "422 dato non valido" a un segnalibro vecchio e' un estratto conto
 * che l'utente non scarica.
 *
 * IL LINK DEL BROKER ERA ROTTO. `broker/client-show.blade.php` puntava a
 * `portal.statement?account={id}`: un parametro che nessuno leggeva. Il broker
 * apriva la pagina e si ritrovava, senza alcun avviso, l'estratto del PROPRIO
 * conto al posto di quello del cliente. Ora c'e' una rotta broker dedicata che
 * passa dallo stesso controllo di mandato delle altre pagine broker.
 */
class StatementController extends Controller
{
    /**
     * Oltre questa soglia il dettaglio riga per riga non entra in un PDF
     * leggibile (e dompdf impiega minuti a comporlo): i riepiloghi restano,
     * il dettaglio si scarica in CSV. Vedi l'avviso in pdf/statement.blade.php.
     */
    private const MAX_RIGHE_PDF = 1200;

    public function __construct(private readonly StatementBuilder $builder)
    {
    }

    // ── Portale (azienda e privati) ─────────────────────────────────────────

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $root     = $this->resolveAccount($user);
        $accounts = $this->selectableAccounts($root);
        $account  = $this->pickAccount($accounts, $request->query('conto'));

        return view('portal.statement', [
            'pageTitle'     => 'Estratto conto',
            'account'       => $account,
            'accounts'      => $accounts,
            'period'        => StatementPeriod::fromRequest($request),
            'quickPresets'  => StatementPeriod::quickPresets(),
            'months'        => $this->availableMonths($account),
            'quarters'      => $this->availableQuarters($account),
            'years'         => $this->availableYears($account),
            'downloadRoute' => route('portal.statement.download'),
            'activeNav'     => 'movimenti',
        ]);
    }

    public function download(Request $request): Response|StreamedResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $root     = $this->resolveAccount($user);
        $accounts = $this->selectableAccounts($root);
        $account  = $this->pickAccount($accounts, $request->query('conto'));

        return $this->produce($account, $request);
    }

    // ── Backoffice admin: qualsiasi conto ───────────────────────────────────

    public function adminDownload(Request $request, Account $account): Response|StreamedResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $account->loadMissing(['company', 'ownerUser']);

        return $this->produce($account, $request);
    }

    // ── Broker: il conto di un cliente in mandato ───────────────────────────

    /**
     * Il broker non sceglie il conto da un parametro: lo si ricava dall'azienda
     * su cui ha gia' il mandato, con lo stesso controllo che protegge le altre
     * pagine broker. Passare l'id del conto in query sarebbe una porta aperta
     * sull'estratto di chiunque.
     */
    public function brokerDownload(Request $request, Company $company): Response|StreamedResponse
    {
        $user = $request->user();

        // Stessi tre controlli di BrokerController::authorizeAccess(): backoffice
        // pieno passa, altrimenti serve il ruolo broker E l'assegnazione su
        // QUESTA azienda. Replicati e non ereditati perche' il metodo la' e'
        // privato; se cambiano le regole del mandato, vanno cambiati insieme.
        if (! $user->hasFullBackofficeAccess()) {
            abort_unless($user->hasRole('broker'), 403, 'Accesso riservato agli operatori broker.');
            abort_unless((int) $company->broker_user_id === (int) $user->id, 403, 'Non sei il broker assegnato a questa azienda.');
        }

        $account = Account::query()
            ->with(['company', 'ownerUser'])
            ->where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->firstOrFail();

        return $this->produce($account, $request);
    }

    // ────────────────────────────────────────────────────────────────────────

    private function produce(Account $account, Request $request): Response|StreamedResponse
    {
        $period = StatementPeriod::fromRequest($request);
        $data   = $this->builder->build($account, $period);

        return match ((string) $request->query('formato', 'pdf')) {
            'csv'         => $this->csv($data),
            'prima-nota'  => $this->primaNota($data),
            default       => $this->pdf($data),
        };
    }

    private function pdf(array $data): Response
    {
        $righe = count($data['rows']);
        $omesse = $righe > self::MAX_RIGHE_PDF;

        // array_merge e non l'unione `+`: con l'unione le chiavi gia' presenti in
        // $data vincerebbero, e proprio 'rows' — quella che qui va svuotata —
        // resterebbe piena.
        $pdf = Pdf::loadView('pdf.statement', array_merge($data, [
            'documentTitle' => $data['period']->documentTitle(),
            'righeOmesse'   => $omesse,
            'maxRighe'      => self::MAX_RIGHE_PDF,
            'rows'          => $omesse ? [] : $data['rows'],
        ]))->setPaper('a4', 'portrait');

        // "Pagina X di Y" si disegna a rendering finito. Durante la
        // composizione il totale delle pagine non esiste ancora: `counter(pages)`
        // in dompdf vale 0, ed e' il motivo per cui il piede in CSS diceva
        // "Pagina 2 di 0". `page_text()` sostituisce {PAGE_NUM}/{PAGE_COUNT} su
        // ogni pagina quando il documento e' completo.
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $dompdf->getCanvas()->page_text(
            487, 796, 'Pagina {PAGE_NUM} di {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.48, 0.50, 0.53],
        );

        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->filename($data, 'estratto-conto', 'pdf'),
            ),
        ]);
    }

    /**
     * CSV dell'estratto: le stesse righe del PDF, precedute da poche righe di
     * intestazione. Il preambolo c'e' di proposito — questo file viaggia da solo
     * in allegato a una mail, e senza intestatario, conto e periodo scritti
     * dentro non si sa piu' di chi e di quando sia.
     */
    private function csv(array $data): StreamedResponse
    {
        $filename = $this->filename($data, 'estratto-conto', 'csv');

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: senza, Excel sbaglia gli accenti

            $meta = [
                ['Estratto conto KMoney'],
                ['Intestatario', $data['holder']['name']],
                ['Conto', $data['account']->account_number],
                ['Periodo', $data['period']->label],
                ['Dal', $data['period']->start->format('d/m/Y'), 'Al', $data['period']->end->format('d/m/Y')],
                ['Saldo iniziale (KY)', ky_format($data['opening'])],
                ['Totale entrate (KY)', ky_format($data['totals']['entrate'])],
                ['Totale uscite (KY)', ky_format($data['totals']['uscite'])],
                ['Saldo finale (KY)', ky_format($data['closing'])],
                ['Emesso il', $data['generatedAt']->format('d/m/Y H:i')],
                [],
            ];

            foreach ($meta as $riga) {
                fputcsv($out, $riga, ';');
            }

            fputcsv($out, [
                'Data', 'Riferimento', 'Tipologia', 'Controparte', 'Causale',
                'Entrate KY', 'Uscite KY', 'Saldo KY',
            ], ';');

            foreach ($data['rows'] as $row) {
                fputcsv($out, [
                    $row['date']->format('d/m/Y'),
                    $row['reference'],
                    $row['kindLabel'],
                    $row['counterparty'],
                    $row['description'],
                    $row['credit'] ? ky_format($row['amount']) : '',
                    $row['credit'] ? '' : ky_format($row['amount']),
                    ky_format($row['balance']),
                ], ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Prima nota in partita doppia. Il tracciato e' lo stesso gia' in uso in
     * PortalController::exportPrimaNota(): cambiarlo qui costringerebbe il
     * commercialista a rifare il mapping di importazione.
     */
    private function primaNota(array $data): StreamedResponse
    {
        $filename = $this->filename($data, 'prima-nota', 'csv');

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Data', 'N. Documento', 'Descrizione / Causale',
                'Conto Dare', 'Importo Dare (KY)',
                'Conto Avere', 'Importo Avere (KY)',
                'Tipo operazione',
            ], ';');

            foreach ($data['rows'] as $row) {
                $importo     = ky_format($row['amount']);
                $descrizione = $row['description'] !== '' ? $row['description'] : $row['kindLabel'];
                $ndoc        = $row['reference'] !== '' ? $row['reference'] : '—';

                fputcsv($out, $row['credit']
                    ? [$row['date']->format('d/m/Y'), $ndoc, $descrizione,
                       'Cassa KY', $importo,
                       'Clienti - ' . $row['counterparty'], $importo, 'Entrata']
                    : [$row['date']->format('d/m/Y'), $ndoc, $descrizione,
                       'Fornitori - ' . $row['counterparty'], $importo,
                       'Cassa KY', $importo, 'Uscita'],
                    ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filename(array $data, string $prefisso, string $estensione): string
    {
        $account = $data['account'];
        $chi = $account->company?->slug ?: ('conto-' . $account->id);

        return $prefisso . '-' . $chi . '-' . $data['period']->fileSlug() . '.' . $estensione;
    }

    // ── Conti ───────────────────────────────────────────────────────────────

    private function resolveAccount(User $user): Account
    {
        if ($user->managed_account_id !== null) {
            $account = Account::with(['company', 'ownerUser'])->findOrFail($user->managed_account_id);

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

    /**
     * Il conto principale e i suoi sottoconti. Ogni sottoconto ha un ledger
     * proprio: sommarli in un unico estratto farebbe saltare la quadratura col
     * saldo finale, quindi si sceglie quale estratto si vuole.
     *
     * @return \Illuminate\Support\Collection<int,Account>
     */
    private function selectableAccounts(Account $root): \Illuminate\Support\Collection
    {
        return collect([$root])->merge(
            $root->childAccounts()->with(['company', 'ownerUser'])->orderBy('id')->get(),
        );
    }

    /** Scelta del conto blindata sull'elenco autorizzato: mai un id preso dalla query. */
    private function pickAccount(\Illuminate\Support\Collection $accounts, mixed $requested): Account
    {
        $id = is_numeric($requested) ? (int) $requested : null;

        return $accounts->firstWhere('id', $id) ?? $accounts->first();
    }

    // ── Periodi disponibili per i menu a tendina ────────────────────────────

    private function firstMovementDate(Account $account): ?CarbonImmutable
    {
        $first = $account->ledgerEntries()->oldest('posted_at')->value('posted_at');

        return $first ? CarbonImmutable::parse($first) : null;
    }

    private function availableMonths(Account $account): array
    {
        $first = $this->firstMovementDate($account);

        if (! $first) {
            return [];
        }

        $mesi = [
            1 => 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
            'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre',
        ];

        $cursor = $first->startOfMonth();
        $fine   = CarbonImmutable::now()->startOfMonth();
        $out    = [];

        while ($cursor->lessThanOrEqualTo($fine)) {
            $out[] = [
                'value' => $cursor->format('Y-m'),
                'label' => $mesi[(int) $cursor->format('n')] . ' ' . $cursor->format('Y'),
            ];
            $cursor = $cursor->addMonth();
        }

        return array_reverse($out);
    }

    private function availableQuarters(Account $account): array
    {
        $first = $this->firstMovementDate($account);

        if (! $first) {
            return [];
        }

        $cursor = $first->startOfQuarter();
        $fine   = CarbonImmutable::now()->startOfQuarter();
        $out    = [];

        while ($cursor->lessThanOrEqualTo($fine)) {
            $q = (int) $cursor->quarter;
            $out[] = [
                'value' => $cursor->format('Y') . '-T' . $q,
                'label' => $q . '° trimestre ' . $cursor->format('Y'),
            ];
            $cursor = $cursor->addQuarter();
        }

        return array_reverse($out);
    }

    private function availableYears(Account $account): array
    {
        $first = $this->firstMovementDate($account);

        if (! $first) {
            return [];
        }

        $out = [];

        for ($anno = (int) CarbonImmutable::now()->format('Y'); $anno >= (int) $first->format('Y'); $anno--) {
            $out[] = ['value' => (string) $anno, 'label' => 'Anno ' . $anno];
        }

        return $out;
    }
}
