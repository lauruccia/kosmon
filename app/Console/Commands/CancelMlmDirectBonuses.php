<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\MlmBonusPayout;
use App\Models\User;
use App\Services\MlmWalletService;
use Illuminate\Console\Command;

/**
 * Annulla i BONUS DIRETTI KNM gia' generati e non ancora liquidati, e storna
 * il KY che era stato accreditato nel cassetto kmoney (2026-08-14, richiesta
 * di Laura contestuale alla disattivazione dei Bonus Diretti — vedi
 * SystemSetting::mlmDirectBonusesEnabled()).
 *
 * Spegnere l'interruttore impedisce solo che ne nascano di NUOVI: i payout
 * gia' creati resterebbero in `mlm_bonus_payouts` con stato 'pending', pronti
 * a finire nella prima liquidazione EUR utile, e il KY corrispondente
 * resterebbe sul conto dell'agente. Questo comando chiude entrambe le cose.
 *
 * PERIMETRO — tocca SOLO `kind = 'diretto'` in stato 'pending':
 *  - i bonus di struttura (cascata BasiQ) e gli Extra Bonus di grado non
 *    vengono mai toccati, nemmeno se pendenti;
 *  - i Bonus Diretti gia' 'approved'/'paid' non vengono toccati: sono gia'
 *    entrati in una liquidazione EUR, annullarli qui lascerebbe la
 *    liquidazione incoerente. Vanno gestiti da /admin/mlm-payouts.
 *
 * Lo storno KY e' un movimento reale verso la Cassa Circuito
 * (MlmWalletService::reverseBonusPayout()): se l'agente ha gia' speso quel KY
 * il suo saldo puo' andare in negativo — voluto, vedi il docblock del metodo.
 *
 * Idempotente: rilanciarlo non annulla due volte (i payout sono gia'
 * 'cancelled') ne' toglie KY due volte (idempotency_key dedicata sul
 * cassetto). Di default gira in SIMULAZIONE e non scrive nulla: serve
 * `--force` per applicare davvero.
 */
class CancelMlmDirectBonuses extends Command
{
    protected $signature = 'mlm:cancel-direct-bonuses {--force : Applica davvero (senza questa opzione mostra solo cosa verrebbe annullato)}';

    protected $description = 'Annulla i Bonus Diretti KNM pendenti e storna il KY accreditato nel cassetto kmoney';

    public function handle(MlmWalletService $wallet): int
    {
        $pending = MlmBonusPayout::with('beneficiary')
            ->where('kind', 'diretto')
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nessun Bonus Diretto pendente da annullare.');

            return self::SUCCESS;
        }

        $totalEurCents = (int) $pending->sum('amount_eur_cents');
        $apply = (bool) $this->option('force');

        foreach ($pending as $payout) {
            $this->line(sprintf(
                ' - #%d %s — %s € (settimana %s)',
                $payout->id,
                $payout->beneficiary->name ?? 'agente #' . $payout->beneficiary_user_id,
                number_format($payout->amount_eur_cents / 100, 2, ',', '.'),
                $payout->week_ending?->format('d/m/Y') ?? '—',
            ));
        }

        if (! $apply) {
            $this->warn(sprintf(
                'SIMULAZIONE: %d Bonus Diretti per %s € verrebbero annullati (e il KY corrispondente stornato). Rilancia con --force per applicare.',
                $pending->count(),
                number_format($totalEurCents / 100, 2, ',', '.'),
            ));

            return self::SUCCESS;
        }

        $cancelled = 0;
        $reversed = 0;

        foreach ($pending as $payout) {
            // Prima lo storno KY, poi il cambio di stato: se lo storno
            // fallisse a meta' (conto mancante, nessun super admin...) il
            // payout resta 'pending' e il comando si puo' rilanciare, invece
            // di lasciare un bonus "annullato" col KY ancora in mano
            // all'agente e nessuna traccia di cosa manca.
            $kyReversed = $wallet->reverseBonusPayout($payout);
            if ($kyReversed) {
                $reversed++;
            }

            $payout->forceFill(['status' => 'cancelled'])->save();
            $cancelled++;

            AuditLog::create([
                'actor_user_id' => null,
                'event' => 'mlm.direct_bonus_cancelled',
                'auditable_type' => User::class,
                'auditable_id' => $payout->beneficiary_user_id,
                'context' => [
                    'bonus_payout_id' => $payout->id,
                    'amount_eur_cents' => (int) $payout->amount_eur_cents,
                    'week_ending' => $payout->week_ending?->toDateString(),
                    // false = non c'era KY da stornare (accredito nel
                    // cassetto mai avvenuto, o storno gia' fatto in una
                    // esecuzione precedente).
                    'ky_reversed' => $kyReversed,
                ],
            ]);
        }

        $this->info(sprintf(
            'Bonus Diretti annullati: %d (%s €). Storni KY nel cassetto kmoney: %d.',
            $cancelled,
            number_format($totalEurCents / 100, 2, ',', '.'),
            $reversed,
        ));

        return self::SUCCESS;
    }
}
