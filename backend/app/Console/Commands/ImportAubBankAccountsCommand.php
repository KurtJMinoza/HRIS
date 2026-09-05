<?php

namespace App\Console\Commands;

use App\Models\EmployeeBankAccount;
use App\Models\User;
use App\Services\BankAccountFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportAubBankAccountsCommand extends Command
{
    protected $signature = 'hr:import-aub-bank-accounts
                            {file : Path to the AUB NetPay upload .xls/.xlsx file}
                            {--bank-name=Asia United Bank : Bank name stored on the employee profile}
                            {--bank-code=AUB : Bank code stored on the employee profile}
                            {--dry-run : Preview matches without writing to the database}';

    protected $description = 'Import AUB account numbers from a payroll upload spreadsheet into employee bank profiles.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readSpreadsheetRows($path);
        if ($rows === []) {
            $this->warn('No account rows found in the spreadsheet.');

            return self::SUCCESS;
        }

        $users = User::query()
            ->whereNotNull('employee_code')
            ->get(['id', 'name', 'first_name', 'last_name', 'employee_code']);

        $byTokenKey = [];
        foreach ($users as $user) {
            $byTokenKey[$this->tokenKey((string) $user->name)][] = $user;
        }

        $matched = [];
        $unmatched = [];
        foreach ($rows as $row) {
            $user = $this->resolveUser($row, $users, $byTokenKey);
            if ($user instanceof User) {
                $matched[] = [$row, $user];
            } else {
                $unmatched[] = $row;
            }
        }

        $this->info('Rows in file: '.count($rows));
        $this->info('Matched employees: '.count($matched));
        $this->info('Unmatched rows: '.count($unmatched));

        if ($unmatched !== []) {
            $this->warn('Unmatched names:');
            foreach ($unmatched as $row) {
                $this->line("  - {$row['name']} ({$row['account_number']})");
            }
        }

        $bankName = trim((string) $this->option('bank-name'));
        $bankCode = strtoupper(trim((string) $this->option('bank-code')));
        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        foreach ($matched as [$row, $user]) {
            $payload = BankAccountFormatter::validateAndNormalize([
                'bank_name' => $bankName,
                'bank_code' => $bankCode,
                'account_number' => $row['account_number'],
            ]);

            $this->line(sprintf(
                '%s %s → %s (%s)',
                $dryRun ? '[dry-run]' : '[save]',
                $row['name'],
                $user->name,
                $payload['account_number']
            ));

            if ($dryRun) {
                $updated++;

                continue;
            }

            $record = EmployeeBankAccount::query()->firstOrNew(['user_id' => (int) $user->id]);
            $record->fill($payload);
            $record->save();
            $updated++;
        }

        $this->info(($dryRun ? 'Would update' : 'Updated')." {$updated} bank account(s).");

        return $unmatched === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array{name:string,account_number:string,key:string,parsed:array{last:string,first:string}}> */
    private function readSpreadsheetRows(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $rows = [];

        for ($rowNumber = 4; $rowNumber <= (int) $sheet->getHighestRow(); $rowNumber++) {
            $name = trim((string) $sheet->getCell('B'.$rowNumber)->getFormattedValue());
            $accountNumber = preg_replace('/\D+/', '', (string) $sheet->getCell('C'.$rowNumber)->getFormattedValue()) ?? '';
            if ($name === '' || $accountNumber === '') {
                continue;
            }
            if (strlen($accountNumber) !== 12) {
                $this->warn("Skipping row {$rowNumber}: invalid account number length for {$name} ({$accountNumber}).");

                continue;
            }

            $rows[] = [
                'name' => $name,
                'account_number' => $accountNumber,
                'key' => $this->tokenKey($name),
                'parsed' => $this->parseSheetName($name),
            ];
        }

        return $rows;
    }

    /** @param  array{name:string,account_number:string,key:string,parsed:array{last:string,first:string}}  $row
     * @param  array<string, list<User>>  $byTokenKey
     */
    private function resolveUser(array $row, Collection $users, array $byTokenKey): ?User
    {
        $hits = $byTokenKey[$row['key']] ?? [];
        if (count($hits) === 1) {
            return $hits[0];
        }

        if (count($hits) > 1) {
            $best = $this->bestFirstNameMatch($hits, $row['parsed']['first']);
            if ($best instanceof User) {
                return $best;
            }
        }

        $candidates = $users->filter(function (User $user) use ($row): bool {
            $last = $this->asciiUpper((string) $user->last_name);
            $parsedLast = $row['parsed']['last'];
            $parsedFirst = $row['parsed']['first'];

            return $last !== '' && ($last === $parsedLast || $last === $parsedFirst);
        });

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($candidates->count() > 1) {
            $best = null;
            $bestScore = -1;
            foreach ($candidates as $candidate) {
                $score = max(
                    $this->firstNameScore($row['parsed']['first'], (string) $candidate->first_name),
                    $this->firstNameScore($row['parsed']['last'], (string) $candidate->first_name),
                );
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $candidate;
                }
            }
            if ($best instanceof User && $bestScore >= 70) {
                return $best;
            }
        }

        // Final fallback for minor first-name typos on unique last names.
        $lastMatches = $users->filter(fn (User $user) => $this->asciiUpper((string) $user->last_name) === $row['parsed']['last']);
        if ($lastMatches->count() === 1) {
            $candidate = $lastMatches->first();
            if ($this->firstNameScore($row['parsed']['first'], (string) $candidate->first_name) >= 65) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param  list<User>  $users */
    private function bestFirstNameMatch(array $users, string $firstName): ?User
    {
        $best = null;
        $bestScore = -1;
        foreach ($users as $user) {
            $score = $this->firstNameScore($firstName, (string) $user->first_name);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $user;
            }
        }

        return $bestScore >= 70 ? $best : null;
    }

    /** @return array{last:string,first:string} */
    private function parseSheetName(string $name): array
    {
        $name = trim($name);
        if (str_contains($name, ',')) {
            [$last, $firstPart] = array_map('trim', explode(',', $name, 2));

            return [
                'last' => $this->asciiUpper($last),
                'first' => $this->asciiUpper(strtok($firstPart, ' ') ?: ''),
            ];
        }

        $parts = preg_split('/\s+/', $this->asciiUpper($name)) ?: [];

        return [
            'last' => $parts[0] ?? '',
            'first' => $parts[1] ?? '',
        ];
    }

    /** @return list<string> */
    private function nameTokens(string $name): array
    {
        $name = $this->asciiUpper($name);
        $name = str_replace([',', '.', '-', "'"], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $skip = ['JR', 'SR', 'II', 'III', 'IV', 'V'];
        $parts = array_values(array_filter(
            explode(' ', $name),
            fn (string $part) => $part !== '' && strlen($part) >= 2 && ! in_array($part, $skip, true)
        ));
        sort($parts);

        return $parts;
    }

    private function tokenKey(string $name): string
    {
        return implode(' ', $this->nameTokens($name));
    }

    private function asciiUpper(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = str_replace(['Ñ', 'ñ'], ['N', 'n'], $value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtoupper(trim(is_string($ascii) ? $ascii : $value));
    }

    private function firstNameScore(string $a, string $b): int
    {
        $a = $this->asciiUpper($a);
        $b = $this->asciiUpper($b);
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            return 100;
        }
        if (str_starts_with($a, $b) || str_starts_with($b, $a)) {
            return 80;
        }

        return max(0, 100 - levenshtein($a, $b));
    }
}
