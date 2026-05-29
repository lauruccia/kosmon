<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairImportedProfiles extends Command
{
    protected $signature = 'kmoney:repair-imported-profiles
        {file : Percorso al dump SQL del vecchio sito}
        {--dry-run : Mostra cosa verrebbe aggiornato senza modificare il DB}
        {--force : Salta la conferma interattiva}';

    protected $description = 'Ripara tipo profilo, piano abbonamento e limiti conto dopo import da KosmOMoney';

    public function handle(): int
    {
        $file = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($file)) {
            $this->error("File non trovato: {$file}");
            return self::FAILURE;
        }

        $this->info('Leggo dump legacy...');
        $users = $this->extractUsers(file_get_contents($file));
        $this->info('  Utenti unici nel dump: ' . count($users));

        $stats = [
            'matched' => 0,
            'missing' => 0,
            'private' => 0,
            'company' => 0,
            'active' => 0,
            'inactive' => 0,
            'plans' => 0,
            'limits' => 0,
            'orphan_companies' => 0,
        ];

        if (! $dryRun && ! $this->option('force')) {
            if (! $this->confirm('Procedere con la riparazione del database?', true)) {
                $this->warn('Operazione annullata.');
                return self::FAILURE;
            }
        }

        foreach ($users as $legacy) {
            $user = User::with(['company', 'ownedAccounts'])
                ->where('email', $legacy['email'])
                ->first();

            if (! $user) {
                $stats['missing']++;
                continue;
            }

            $stats['matched']++;
            $holderType = $this->mapHolderType($legacy);
            $isActive = (int) ($legacy['status'] ?? 1) === 1;
            $plan = $holderType === 'company' ? $this->mapSubscriptionPlan($legacy['store_plan_purchase']) : null;
            $limits = $this->mapLegacyLimits($legacy);
            $account = $user->ownedAccounts->first()
                ?? Account::where('owner_user_id', $user->id)->first()
                ?? ($user->managed_account_id ? Account::find($user->managed_account_id) : null);

            $oldCompany = $user->company;

            if ($holderType === 'private') {
                $stats['private']++;
            } else {
                $stats['company']++;
            }
            if ($isActive) {
                $stats['active']++;
            } else {
                $stats['inactive']++;
            }
            if ($plan !== null) {
                $stats['plans']++;
            }
            if ($limits['has_limits']) {
                $stats['limits']++;
            }

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($user, $account, $oldCompany, $holderType, $isActive, $plan, $limits, &$stats): void {
                $user->forceFill([
                    'account_holder_type' => $holderType,
                    'company_id' => $holderType === 'company' ? $user->company_id : null,
                    'role' => $holderType === 'private' ? 'private-owner' : ($user->role === 'private-owner' ? 'owner' : $user->role),
                    'is_active' => $isActive,
                    'circuit_capacity_limit' => null,
                    'negative_balance_limit' => $limits['negative_balance_limit'],
                    'daily_transaction_limit' => $limits['daily_transaction_limit'],
                    'monthly_transaction_limit' => $limits['monthly_transaction_limit'],
                    'per_movement_limit' => null,
                    'transfer_limits_use_defaults' => false,
                    'managed_account_id' => null,
                ])->save();

                if ($account) {
                    $account->forceFill([
                        'company_id' => $holderType === 'company' ? $user->company_id : null,
                        'owner_user_id' => $user->id,
                        'owner_type' => $holderType,
                        'status' => $isActive ? 'active' : 'suspended',
                        'allow_negative_balance' => $limits['negative_balance_limit'] > 0,
                        'max_balance' => $limits['max_balance'],
                        'daily_outgoing_limit' => $limits['daily_transaction_limit'],
                    ])->save();
                }

                if ($holderType === 'company' && $user->company) {
                    $user->company->forceFill([
                        'subscription_plan' => $plan,
                        'status' => $isActive ? 'active' : 'suspended',
                    ])->save();
                }

                if ($holderType === 'private' && $oldCompany) {
                    $oldCompany->forceFill(['subscription_plan' => null])->save();

                    $hasUsers = User::where('company_id', $oldCompany->id)->exists();
                    $hasAccounts = Account::where('company_id', $oldCompany->id)->exists();
                    if (! $hasUsers && ! $hasAccounts) {
                        $oldCompany->delete();
                        $stats['orphan_companies']++;
                    }
                }
            });
        }

        $this->newLine();
        $this->table(['Voce', 'Totale'], [
            ['Utenti abbinati', $stats['matched']],
            ['Utenti dump non trovati nel DB nuovo', $stats['missing']],
            ['Profili privati da dump', $stats['private']],
            ['Profili azienda da dump', $stats['company']],
            ['Attivi da dump', $stats['active']],
            ['Disattivi da dump', $stats['inactive']],
            ['Aziende con piano riconosciuto', $stats['plans']],
            ['Utenti con limiti legacy', $stats['limits']],
            ['Aziende orfane private eliminate', $stats['orphan_companies']],
        ]);

        if ($dryRun) {
            $this->warn('DRY-RUN: nessuna modifica eseguita.');
            return self::SUCCESS;
        }

        $this->info('Riparazione completata.');

        return self::SUCCESS;
    }

    private function extractUsers(string $content): array
    {
        $users = [];
        $seen = [];

        preg_match_all('/INSERT INTO `users`[^;]+;/s', $content, $matches);

        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $v = $this->splitFields($row);
                if (count($v) < 68) {
                    continue;
                }

                $email = strtolower(trim((string) ($v[15] ?? '')));
                if ($email === '' || isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;

                $users[] = [
                    'id' => (int) $v[0],
                    'account_type' => $v[3] ?? null,
                    'store_plan_purchase' => $v[6] ?? null,
                    'email' => $email,
                    'status' => (int) ($v[44] ?? 1),
                    'daily_transfer_limit' => $v[63] ?? null,
                    'monthly_transfer_limit' => $v[64] ?? null,
                    'minimum_balance_limit' => $v[66] ?? null,
                    'maximum_balance_limit' => $v[67] ?? null,
                ];
            }
        }

        return $users;
    }

    private function mapHolderType(array $old): string
    {
        return strtolower(trim((string) ($old['account_type'] ?? ''))) === 'individual'
            ? 'private'
            : 'company';
    }

    private function mapSubscriptionPlan(mixed $value): ?string
    {
        $plan = strtolower(trim((string) $value));

        return match ($plan) {
            'ecommerce' => 'ecommerce',
            'vetrina' => 'vetrina',
            'biglietto', 'biglietto da visita', 'business card' => 'biglietto',
            'anagrafica' => 'anagrafica',
            default => null,
        };
    }

    private function mapLegacyLimits(array $old): array
    {
        $minimumBalance = $this->legacyInteger($old['minimum_balance_limit'] ?? null);
        $daily = $this->legacyNullableInteger($old['daily_transfer_limit'] ?? null);
        $monthly = $this->legacyNullableInteger($old['monthly_transfer_limit'] ?? null);
        $max = $this->legacyNullableInteger($old['maximum_balance_limit'] ?? null);

        return [
            'negative_balance_limit' => $minimumBalance < 0 ? abs($minimumBalance) : 0,
            'daily_transaction_limit' => $daily,
            'monthly_transaction_limit' => $monthly,
            'max_balance' => $max,
            'has_limits' => $minimumBalance !== 0 || $daily !== null || $monthly !== null || $max !== null,
        ];
    }

    private function legacyNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round((float) $value);
    }

    private function legacyInteger(mixed $value): int
    {
        return $this->legacyNullableInteger($value) ?? 0;
    }

    private function parseInsertRows(string $block): array
    {
        $valuesPos = strpos($block, 'VALUES');
        if ($valuesPos === false) {
            return [];
        }

        $text = rtrim(substr($block, $valuesPos + 6), ';');
        $rows = [];
        $depth = 0;
        $buf = '';
        $inStr = false;
        $esc = false;
        $sc = '';

        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $c = $text[$i];
            if ($esc) {
                $buf .= $c;
                $esc = false;
            } elseif ($c === '\\' && $inStr) {
                $buf .= $c;
                $esc = true;
            } elseif ($inStr) {
                $buf .= $c;
                if ($c === $sc) {
                    $inStr = false;
                }
            } elseif ($c === "'" || $c === '"') {
                $inStr = true;
                $sc = $c;
                $buf .= $c;
            } elseif ($c === '(') {
                $buf = $depth === 0 ? '' : $buf . $c;
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $rows[] = $buf;
                    $buf = '';
                } else {
                    $buf .= $c;
                }
            } elseif ($depth > 0) {
                $buf .= $c;
            }
        }

        return $rows;
    }

    private function splitFields(string $row): array
    {
        $fields = [];
        $buf = '';
        $inStr = false;
        $esc = false;
        $sc = '';
        $depth = 0;

        for ($i = 0, $len = strlen($row); $i < $len; $i++) {
            $c = $row[$i];
            if ($esc) {
                $buf .= $c;
                $esc = false;
            } elseif ($c === '\\' && $inStr) {
                $buf .= $c;
                $esc = true;
            } elseif ($inStr) {
                $buf .= $c;
                if ($c === $sc) {
                    $inStr = false;
                }
            } elseif ($c === "'" || $c === '"') {
                $inStr = true;
                $sc = $c;
                $buf .= $c;
            } elseif (($c === '(' || $c === '{' || $c === '[') && ! $inStr) {
                $depth++;
                $buf .= $c;
            } elseif (($c === ')' || $c === '}' || $c === ']') && ! $inStr) {
                $depth--;
                $buf .= $c;
            } elseif ($c === ',' && $depth === 0) {
                $fields[] = $this->convertField(trim($buf));
                $buf = '';
            } else {
                $buf .= $c;
            }
        }

        if ($buf !== '') {
            $fields[] = $this->convertField(trim($buf));
        }

        return $fields;
    }

    private function convertField(string $val): mixed
    {
        if (strtoupper($val) === 'NULL') {
            return null;
        }

        if (strlen($val) >= 2 && $val[0] === "'" && $val[-1] === "'") {
            return str_replace(
                ["\\'", '\\\\', '\\n', '\\r', '\\t'],
                ["'", '\\', "\n", "\r", "\t"],
                substr($val, 1, -1)
            );
        }

        if (is_numeric($val)) {
            return str_contains($val, '.') ? (float) $val : (int) $val;
        }

        return $val;
    }
}
