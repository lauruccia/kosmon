<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transfer;
use App\Support\StatementPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Il contenuto di un estratto conto: un dataset solo, tre formati.
 *
 * PERCHE' UN SERVIZIO E NON TRE QUERY (09/09/2026). PDF, CSV e prima nota
 * partono tutti da qui. Se ognuno si facesse la sua query, basterebbe un
 * `where` diverso perche' il PDF consegnato al commercialista e il CSV che lo
 * accompagna dicano due cose diverse sullo stesso mese — ed e' esattamente il
 * genere di incoerenza che un commercialista trova e che costa una telefonata.
 *
 * IL SALDO PROGRESSIVO SI CALCOLA QUI, NON SI LEGGE DAL LEDGER. La versione
 * precedente del template PDF leggeva `balance_after` dalla riga di ledger di
 * ogni movimento: una query per riga (N+1 su una relazione nemmeno caricata) e,
 * soprattutto, un saldo che non e' detto risalga al saldo iniziale stampato in
 * testa. Qui si parte dal saldo iniziale e si somma movimento per movimento:
 * l'ultima riga della colonna Saldo coincide sempre, per costruzione, con il
 * saldo finale del riquadro di riepilogo. Un estratto conto che non quadra con
 * se stesso non e' consegnabile.
 *
 * IL SALDO INIZIALE E' RICOSTRUITO ALL'INDIETRO (comportamento ereditato e
 * mantenuto): saldo finale meno i movimenti visibili del periodo. Cosi' la
 * correzione tecnica di apertura ledger del 17/06/2026 — che non deve comparire
 * fra i movimenti del cliente — resta assorbita nel saldo iniziale invece di
 * lasciare l'estratto sbilanciato.
 *
 * SOLO MOVIMENTI CONTABILIZZATI. `status = booked`: i movimenti in attesa o
 * annullati non sono entrati in contabilita' e in un documento per il
 * commercialista non ci vanno.
 *
 * UN CONTO PER VOLTA. Se il conto ha dei sottoconti, i loro movimenti NON
 * entrano in questo estratto: hanno ledger separati e sommarli farebbe saltare
 * la quadratura col saldo finale. Ogni sottoconto ha il suo estratto conto.
 */
class StatementBuilder
{
    public function build(Account $account, StatementPeriod $period): array
    {
        $transfers = $this->transfers($account, $period);

        $closing = $this->closingBalance($account, $period->end);

        $netto = 0;
        foreach ($transfers as $transfer) {
            $netto += $this->signedAmount($transfer, $account);
        }

        $opening = $closing - $netto;

        [$rows, $totals] = $this->rows($transfers, $account, $opening);

        return [
            'account'     => $account,
            'company'     => $account->company,
            'period'      => $period,
            'holder'      => $this->holder($account),
            'opening'     => $opening,
            'closing'     => $closing,
            'totals'      => $totals,
            'rows'        => $rows,
            'monthly'     => $period->spansMultipleMonths() ? $this->monthly($rows, $opening) : [],
            'byKind'      => $this->byKind($rows),
            'generatedAt' => CarbonImmutable::now(),
        ];
    }

    // ── Query ───────────────────────────────────────────────────────────────

    private function transfers(Account $account, StatementPeriod $period): Collection
    {
        return Transfer::query()
            ->excludeLedgerCorrections()
            ->with([
                'fromAccount.company:id,name',
                'fromAccount.ownerUser:id,name',
                'toAccount.company:id,name',
                'toAccount.ownerUser:id,name',
            ])
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                  ->orWhere('to_account_id', $account->id);
            })
            ->where('status', 'booked')
            ->whereBetween('booked_at', [$period->start, $period->end])
            ->orderBy('booked_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Saldo alla fine del periodo, letto dal ledger (l'unica fonte autorevole:
     * include anche cio' che non compare fra i movimenti visibili).
     */
    private function closingBalance(Account $account, CarbonImmutable $end): int
    {
        $entry = $account->ledgerEntries()
            ->where('posted_at', '<=', $end)
            ->latest('posted_at')
            ->latest('id')
            ->first();

        return $entry ? (int) $entry->balance_after : 0;
    }

    // ── Righe e totali ──────────────────────────────────────────────────────

    /** @return array{0:array<int,array<string,mixed>>,1:array<string,int>} */
    private function rows(Collection $transfers, Account $account, int $opening): array
    {
        $rows    = [];
        $balance = $opening;
        $entrate = 0;
        $uscite  = 0;
        $nIn     = 0;
        $nOut    = 0;

        foreach ($transfers as $transfer) {
            $isCredit = (int) $transfer->to_account_id === (int) $account->id;
            $amount   = (int) $transfer->amount;
            $balance += $isCredit ? $amount : -$amount;

            if ($isCredit) {
                $entrate += $amount;
                $nIn++;
            } else {
                $uscite += $amount;
                $nOut++;
            }

            $counterparty = $isCredit ? $transfer->fromAccount : $transfer->toAccount;

            $rows[] = [
                'date'         => CarbonImmutable::parse($transfer->booked_at),
                'reference'    => (string) ($transfer->reference ?? ''),
                'kind'         => (string) ($transfer->kind ?? ''),
                'kindLabel'    => Transfer::kindLabel($transfer->kind),
                'counterparty' => $counterparty?->company?->name ?? $counterparty?->display_name ?? '—',
                'description'  => trim((string) ($transfer->description ?? '')),
                'credit'       => $isCredit,
                'amount'       => $amount,
                'signed'       => $isCredit ? $amount : -$amount,
                'balance'      => $balance,
            ];
        }

        return [$rows, [
            'entrate'      => $entrate,
            'uscite'       => $uscite,
            'netto'        => $entrate - $uscite,
            'count'        => count($rows),
            'countEntrate' => $nIn,
            'countUscite'  => $nOut,
        ]];
    }

    /**
     * Riepilogo per mese solare — la pagina che il commercialista guarda per
     * prima quando il periodo e' un trimestre o un anno.
     */
    private function monthly(array $rows, int $opening): array
    {
        $mesi = [];

        foreach ($rows as $row) {
            $key = $row['date']->format('Y-m');

            if (! isset($mesi[$key])) {
                $mesi[$key] = [
                    'key'     => $key,
                    'label'   => $this->meseLabel($row['date']),
                    'count'   => 0,
                    'entrate' => 0,
                    'uscite'  => 0,
                    'closing' => $opening,
                ];
            }

            $mesi[$key]['count']++;
            if ($row['credit']) {
                $mesi[$key]['entrate'] += $row['amount'];
            } else {
                $mesi[$key]['uscite'] += $row['amount'];
            }
            $mesi[$key]['closing'] = $row['balance'];
        }

        return array_values($mesi);
    }

    /** Riepilogo per tipologia di operazione. */
    private function byKind(array $rows): array
    {
        $tipi = [];

        foreach ($rows as $row) {
            $key = $row['kind'] !== '' ? $row['kind'] : 'altro';

            if (! isset($tipi[$key])) {
                $tipi[$key] = [
                    'label'   => $row['kindLabel'],
                    'count'   => 0,
                    'entrate' => 0,
                    'uscite'  => 0,
                ];
            }

            $tipi[$key]['count']++;
            if ($row['credit']) {
                $tipi[$key]['entrate'] += $row['amount'];
            } else {
                $tipi[$key]['uscite'] += $row['amount'];
            }
        }

        uasort($tipi, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_values($tipi);
    }

    // ── Intestatario ────────────────────────────────────────────────────────

    /**
     * I dati dell'intestatario che devono comparire in testa al documento.
     * Azienda e privato hanno anagrafiche diverse: le righe vuote non si
     * stampano affatto, invece di lasciare "P.IVA —" in un documento che va in
     * mano a un terzo.
     *
     * @return array{name:string,type:string,lines:array<int,array{label:string,value:string}>}
     */
    private function holder(Account $account): array
    {
        $lines = [];
        $company = $account->company;

        if ($company) {
            $name = (string) $company->name;

            $this->pushLine($lines, 'Partita IVA', $company->vat_number);
            $this->pushLine($lines, 'Codice fiscale', $company->fiscal_code);
            $this->pushLine($lines, 'Sede', $this->joinNonEmpty([$company->address, $company->city]));
            $this->pushLine($lines, 'Settore', $company->sector);
        } else {
            $owner = $account->ownerUser;
            $name  = (string) ($owner?->name ?? $account->display_name);

            $this->pushLine($lines, 'Codice fiscale', $owner?->fiscal_code);
            $this->pushLine($lines, 'Residenza', $this->joinNonEmpty([
                $owner?->residence_address,
                $this->joinNonEmpty([$owner?->residence_zip, $owner?->residence_city], ' '),
                $owner?->residence_province ? '(' . $owner->residence_province . ')' : null,
            ]));
        }

        return [
            'name'  => $name !== '' ? $name : $account->display_name,
            'type'  => $company ? 'Azienda' : 'Privato',
            'lines' => $lines,
        ];
    }

    private function pushLine(array &$lines, string $label, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value !== '') {
            $lines[] = ['label' => $label, 'value' => $value];
        }
    }

    private function joinNonEmpty(array $parts, string $glue = ', '): string
    {
        return implode($glue, array_filter(array_map(
            fn ($p) => trim((string) $p),
            $parts,
        ), fn ($p) => $p !== ''));
    }

    private function signedAmount(Transfer $transfer, Account $account): int
    {
        return (int) $transfer->to_account_id === (int) $account->id
            ? (int) $transfer->amount
            : -(int) $transfer->amount;
    }

    private function meseLabel(CarbonImmutable $date): string
    {
        $mesi = [
            1 => 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
            'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre',
        ];

        return $mesi[(int) $date->format('n')] . ' ' . $date->format('Y');
    }
}
