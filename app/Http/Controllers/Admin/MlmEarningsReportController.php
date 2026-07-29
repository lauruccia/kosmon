<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report guadagni MLM per l'admin (2026-07-29, richiesta di Laura: "l'admin
 * deve avere contezza degli importi pagati per non pagarli due volte" +
 * "deve poter vedere i report di guadagni di tutti gli agenti, di un singolo
 * agente ecc"). Distinto da Admin\MlmPayoutController: quello gestisce il
 * FLUSSO delle liquidazioni (approva/paga/rifiuta), questo e' la vista di
 * SOLA LETTURA sul maturato storico — cosa e' stato generato, cosa e' gia'
 * stato pagato, cosa resta da pagare — per agente o aggregato.
 *
 * "Maturato" (total) = somma di TUTTE le righe mlm_commissions +
 * mlm_bonus_payouts di un agente, in ogni stato. "Pagato" = somma delle
 * stesse righe con status='paid'. "Da pagare" = maturato - pagato (include
 * sia le righe ancora libere che quelle gia' agganciate a una liquidazione
 * pending/approved).
 */
class MlmEarningsReportController extends Controller
{
    use AuthorizesBackoffice;

    /** GET /admin/mlm-report — riepilogo per agente, tutti gli agenti MLM. */
    public function index(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $search = trim((string) $request->query('q', ''));
        $sort = $request->query('sort', 'outstanding');

        $agents = User::query()
            ->where('mlm_role', 'agente')
            ->when($search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->withSum('mlmCommissions as commissions_total_eur_cents', 'amount_eur_cents')
            ->withSum(['mlmCommissions as commissions_paid_eur_cents' => fn ($q) => $q->where('status', 'paid')], 'amount_eur_cents')
            ->withSum('mlmBonusPayouts as bonus_total_eur_cents', 'amount_eur_cents')
            ->withSum(['mlmBonusPayouts as bonus_paid_eur_cents' => fn ($q) => $q->where('status', 'paid')], 'amount_eur_cents')
            ->get()
            ->map(function (User $agent): User {
                $agent->total_earned_eur_cents = (int) $agent->commissions_total_eur_cents + (int) $agent->bonus_total_eur_cents;
                $agent->total_paid_eur_cents = (int) $agent->commissions_paid_eur_cents + (int) $agent->bonus_paid_eur_cents;
                $agent->total_outstanding_eur_cents = $agent->total_earned_eur_cents - $agent->total_paid_eur_cents;

                return $agent;
            });

        $agents = match ($sort) {
            'earned' => $agents->sortByDesc('total_earned_eur_cents'),
            'paid' => $agents->sortByDesc('total_paid_eur_cents'),
            'name' => $agents->sortBy('name'),
            default => $agents->sortByDesc('total_outstanding_eur_cents'),
        };

        $page = (int) $request->query('page', 1);
        $perPage = 30;
        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $agents->slice(($page - 1) * $perPage, $perPage)->values(),
            $agents->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $kpis = [
            'total_earned_eur_cents' => (int) MlmCommission::sum('amount_eur_cents') + (int) MlmBonusPayout::sum('amount_eur_cents'),
            'total_paid_eur_cents' => (int) MlmCommission::where('status', 'paid')->sum('amount_eur_cents') + (int) MlmBonusPayout::where('status', 'paid')->sum('amount_eur_cents'),
        ];
        $kpis['total_outstanding_eur_cents'] = $kpis['total_earned_eur_cents'] - $kpis['total_paid_eur_cents'];

        return view('admin.mlm.earnings.index', [
            'pageTitle' => 'Report guadagni — MLM',
            'activeNav' => 'mlm',
            'agents' => $paginated,
            'search' => $search,
            'sort' => $sort,
            'kpis' => $kpis,
        ]);
    }

    /** GET /admin/mlm-report/{user} — dettaglio guadagni di un singolo agente. */
    public function show(Request $request, User $user): View
    {
        $this->authorizeBackoffice($request->user());

        abort_unless($user->isMlmAgent(), 404);

        $commissionsTotal = (int) $user->mlmCommissions()->sum('amount_eur_cents');
        $commissionsPaid = (int) $user->mlmCommissions()->where('status', 'paid')->sum('amount_eur_cents');
        $bonusTotal = (int) $user->mlmBonusPayouts()->sum('amount_eur_cents');
        $bonusPaid = (int) $user->mlmBonusPayouts()->where('status', 'paid')->sum('amount_eur_cents');

        $totals = [
            'commissions_total_eur_cents' => $commissionsTotal,
            'commissions_paid_eur_cents' => $commissionsPaid,
            'bonus_total_eur_cents' => $bonusTotal,
            'bonus_paid_eur_cents' => $bonusPaid,
            'total_earned_eur_cents' => $commissionsTotal + $bonusTotal,
            'total_paid_eur_cents' => $commissionsPaid + $bonusPaid,
        ];
        $totals['total_outstanding_eur_cents'] = $totals['total_earned_eur_cents'] - $totals['total_paid_eur_cents'];

        $commissions = $user->mlmCommissions()
            ->with(['sourceClient:id,name,email', 'sourceAgent:id,name,email', 'run:id,period_month'])
            ->latest()
            ->paginate(20, ['*'], 'commissioni_page');

        $bonuses = $user->mlmBonusPayouts()
            ->with('event.basiqUser:id,name,email')
            ->latest()
            ->paginate(20, ['*'], 'bonus_page');

        // Storico liquidazioni (batch pending/approved/paid/rejected): utile
        // per verificare a colpo d'occhio cosa e' gia' stato pagato e quando,
        // cosi' da non riliquidare per errore lo stesso periodo.
        $payouts = $user->mlmPayouts()->latest()->take(20)->get();

        return view('admin.mlm.earnings.show', [
            'pageTitle' => 'Guadagni — ' . $user->name,
            'activeNav' => 'mlm',
            'agent' => $user,
            'totals' => $totals,
            'commissions' => $commissions,
            'bonuses' => $bonuses,
            'payouts' => $payouts,
        ]);
    }

    /** GET /admin/mlm-report/esporta — CSV riepilogo di tutti gli agenti. */
    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorizeBackoffice($request->user());

        $filename = 'report_guadagni_mlm_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Agente', 'Email', 'Maturato totale EUR', 'Pagato EUR', 'Da pagare EUR']);

            User::query()
                ->where('mlm_role', 'agente')
                ->withSum('mlmCommissions as commissions_total_eur_cents', 'amount_eur_cents')
                ->withSum(['mlmCommissions as commissions_paid_eur_cents' => fn ($q) => $q->where('status', 'paid')], 'amount_eur_cents')
                ->withSum('mlmBonusPayouts as bonus_total_eur_cents', 'amount_eur_cents')
                ->withSum(['mlmBonusPayouts as bonus_paid_eur_cents' => fn ($q) => $q->where('status', 'paid')], 'amount_eur_cents')
                ->orderBy('name')
                ->chunk(200, function ($agents) use ($out): void {
                    foreach ($agents as $agent) {
                        $earned = (int) $agent->commissions_total_eur_cents + (int) $agent->bonus_total_eur_cents;
                        $paid = (int) $agent->commissions_paid_eur_cents + (int) $agent->bonus_paid_eur_cents;
                        fputcsv($out, [
                            $agent->name,
                            $agent->email,
                            number_format($earned / 100, 2, ',', '.'),
                            number_format($paid / 100, 2, ',', '.'),
                            number_format(($earned - $paid) / 100, 2, ',', '.'),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** GET /admin/mlm-report/{user}/esporta — CSV dettaglio di un singolo agente. */
    public function exportAgentCsv(Request $request, User $user): StreamedResponse
    {
        $this->authorizeBackoffice($request->user());

        abort_unless($user->isMlmAgent(), 404);

        $filename = 'guadagni_' . Str::slug($user->name) . '_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($user): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Tipo', 'Data', 'Descrizione', 'Importo EUR', 'Stato', 'Liquidazione #']);

            $user->mlmCommissions()
                ->with(['sourceClient:id,name', 'run:id,period_month'])
                ->orderBy('created_at')
                ->chunk(200, function ($chunk) use ($out): void {
                    foreach ($chunk as $commission) {
                        fputcsv($out, [
                            'Commissione',
                            $commission->run?->period_month?->format('m/Y'),
                            trim(($commission->type ?? '') . ' — ' . ($commission->sourceClient->name ?? '')),
                            number_format($commission->amount_eur_cents / 100, 2, ',', '.'),
                            $commission->status,
                            $commission->mlm_payout_id ?? '',
                        ]);
                    }
                });

            $user->mlmBonusPayouts()
                ->orderBy('week_ending')
                ->chunk(200, function ($chunk) use ($out): void {
                    foreach ($chunk as $bonus) {
                        fputcsv($out, [
                            'Bonus',
                            $bonus->week_ending?->format('d/m/Y'),
                            $bonus->kind,
                            number_format($bonus->amount_eur_cents / 100, 2, ',', '.'),
                            $bonus->status,
                            $bonus->mlm_payout_id ?? '',
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
