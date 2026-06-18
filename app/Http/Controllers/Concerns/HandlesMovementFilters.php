<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Transfer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Helper condivisi per la costruzione e il filtraggio delle query sui movimenti (transfers)
 * nel backoffice admin. Estratto da AdminController per condividerlo tra i controller Admin/*
 * (dashboard, report, transfers, utenti) — God controller split.
 */
trait HandlesMovementFilters
{
    protected function movementQuery(): Builder
    {
        $relations = [
            'fromAccount.company',
            'fromAccount.ownerUser',
            'toAccount.company',
            'toAccount.ownerUser',
            'initiator',
        ];

        if ($this->supportsTransferRefunds()) {
            $relations[] = 'reversedTransfer';
            $relations[] = 'reversalChildren';
        }

        return Transfer::query()->with($relations);
    }

    protected function movementFilters(Request $request, string $defaultPeriod = 'current_quarter'): array
    {
        $period = (string) $request->query('period', $defaultPeriod);
        $periods = array_keys($this->movementPeriodOptions());

        if (! in_array($period, $periods, true)) {
            $period = $defaultPeriod;
        }

        $now = CarbonImmutable::now();
        $from = null;
        $to = null;

        if ($period === 'custom') {
            $fromInput = trim((string) $request->query('from_date', ''));
            $toInput = trim((string) $request->query('to_date', ''));
            $from = $this->parseFilterDate($fromInput, false);
            $to = $this->parseFilterDate($toInput, true);
        } else {
            [$from, $to] = match ($period) {
                'today' => [$now->startOfDay(), $now->endOfDay()],
                'current_month' => [$now->startOfMonth(), $now->endOfMonth()],
                'current_quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
                'year_to_date' => [$now->startOfYear(), $now->endOfDay()],
                'previous_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
                default => [null, null],
            };
        }

        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return [
            'period' => $period,
            'from_date' => $from?->format('Y-m-d') ?? trim((string) $request->query('from_date', '')),
            'to_date' => $to?->format('Y-m-d') ?? trim((string) $request->query('to_date', '')),
            'from' => $from,
            'to' => $to,
            'label' => $this->movementPeriodOptions()[$period] ?? 'Periodo personalizzato',
        ];
    }

    protected function applyMovementDateFilters(Builder $query, array $filters): void
    {
        if ($filters['from'] instanceof CarbonImmutable) {
            $query->where('booked_at', '>=', $filters['from']);
        }

        if ($filters['to'] instanceof CarbonImmutable) {
            $query->where('booked_at', '<=', $filters['to']);
        }
    }

    protected function applyMovementDateFiltersReturn(Builder $query, array $filters): Builder
    {
        $this->applyMovementDateFilters($query, $filters);
        return $query;
    }

    protected function movementTotals(Builder $query): array
    {
        return [
            'count' => (clone $query)->count(),
            'bookedCount' => (clone $query)->where('status', 'booked')->count(),
            'volume' => (clone $query)->where('status', 'booked')->sum('amount'),
            'refunds' => $this->supportsTransferRefunds()
                ? (clone $query)->whereNotNull('admin_action')->count()
                : 0,
        ];
    }

    protected function movementPeriodOptions(): array
    {
        return [
            'all' => 'Tutti i movimenti',
            'today' => 'Oggi',
            'current_month' => 'Mese corrente',
            'current_quarter' => 'Trimestre corrente',
            'year_to_date' => 'Anno in corso',
            'previous_year' => 'Anno precedente',
            'custom' => 'Intervallo personalizzato',
        ];
    }

    protected function parseFilterDate(string $value, bool $endOfDay): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    protected function supportsTransferRefunds(): bool
    {
        return Schema::hasColumns('transfers', ['reversed_transfer_id', 'refunded_at', 'admin_action']);
    }
}
