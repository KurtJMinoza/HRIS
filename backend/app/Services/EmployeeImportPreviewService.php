<?php

namespace App\Services;

use App\Models\EmployeeGovernmentId;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Builds a full-sheet preview payload using PhpSpreadsheet (same engine as Laravel Excel import),
 * so row/column bounds match the backend import instead of client-side ExcelJS limits.
 */
final class EmployeeImportPreviewService
{
    /**
     * @return array{headers: list<string>, rows: list<array<string, mixed>>, row_count: int, column_count: int}
     */
    public static function build(UploadedFile $file): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        $ext = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($ext, ['csv', 'txt'], true)) {
            return self::buildFromCsv($path);
        }

        return self::buildFromSpreadsheet($path);
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, mixed>>, row_count: int, column_count: int}
     */
    private static function buildFromCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['headers' => [], 'rows' => [], 'row_count' => 0, 'column_count' => 0];
        }

        $matrix = [];
        while (($row = fgetcsv($handle)) !== false) {
            $matrix[] = $row;
        }
        fclose($handle);

        return self::matrixToPreviewPayload($matrix);
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, mixed>>, row_count: int, column_count: int}
     */
    private static function buildFromSpreadsheet(string $path): array
    {
        $xmlMaxRow = 0;
        $xmlMaxColIndex = 0;
        if (is_string($path) && is_file($path)) {
            try {
                $xlsxReader = new Xlsx;
                $info = $xlsxReader->listWorksheetInfo($path);
                if (! empty($info[0])) {
                    $xmlMaxRow = (int) ($info[0]['totalRows'] ?? 0);
                    $xmlMaxColIndex = (int) ($info[0]['lastColumnIndex'] ?? 0) + 1;
                }
            } catch (\Throwable) {
                $xmlMaxRow = 0;
                $xmlMaxColIndex = 0;
            }
        }

        $reader = IOFactory::createReaderForFile($path);
        // Must match Maatwebsite / Laravel-Excel: `readDataOnly` shrinks the used range (often missing
        // trailing blocks of rows) so the preview can show 44 while `Excel::import` still processes 67+.
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $highestRow = max(
            (int) $sheet->getHighestRow(),
            (int) $sheet->getHighestDataRow(),
            $xmlMaxRow,
            1
        );

        $highestColLetter = (string) $sheet->getHighestColumn();
        $highestColIndex = Coordinate::columnIndexFromString($highestColLetter);
        $columnCount = max($highestColIndex, $xmlMaxColIndex, 1);

        $range = 'A1:'.Coordinate::stringFromColumnIndex($columnCount).$highestRow;
        /** @var list<list<mixed>> $matrix */
        $matrix = $sheet->rangeToArray($range, '', true, true, false);

        return self::matrixToPreviewPayload($matrix);
    }

    /**
     * @param  list<array<int, mixed>>  $matrix
     * @return array{headers: list<string>, rows: list<array<string, mixed>>, row_count: int, column_count: int}
     */
    private static function matrixToPreviewPayload(array $matrix): array
    {
        if ($matrix === []) {
            return ['headers' => [], 'rows' => [], 'row_count' => 0, 'column_count' => 0];
        }

        $width = 0;
        foreach ($matrix as $row) {
            $width = max($width, count($row));
        }

        $headerCells = $matrix[0] ?? [];
        $headers = [];
        $headerUsage = [];
        for ($i = 0; $i < $width; $i++) {
            $raw = isset($headerCells[$i]) ? trim((string) $headerCells[$i]) : '';
            $base = $raw !== '' ? $raw : 'column_'.($i + 1);
            $n = ($headerUsage[$base] ?? 0) + 1;
            $headerUsage[$base] = $n;
            $headers[] = $n === 1 ? $base : $base.' ('.$n.')';
        }

        $rows = [];
        for ($r = 1, $max = count($matrix); $r < $max; $r++) {
            $line = $matrix[$r] ?? [];
            $assoc = [];
            foreach ($headers as $ci => $header) {
                $assoc[$header] = $line[$ci] ?? '';
            }
            $rows[] = $assoc;
        }

        return [
            'headers' => $headers,
            'rows' => self::annotateRowsWithDuplicateIssues($rows),
            'row_count' => count($rows),
            'column_count' => count($headers),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function annotateRowsWithDuplicateIssues(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $seen = [];
        $pendingExisting = [
            'email' => [],
            'phone_number' => [],
            'sss_number' => [],
            'philhealth_number' => [],
            'pagibig_number' => [],
            'tin_number' => [],
        ];
        $identifiersByRow = [];

        foreach ($rows as $index => $row) {
            $identifiers = self::duplicateIdentifiersForRow($row);
            $identifiersByRow[$index] = $identifiers;
            foreach ($identifiers as $field => $value) {
                $pendingExisting[$field][$value] = true;
            }
        }

        $existing = self::existingDuplicateIdentifiers($pendingExisting);

        foreach ($rows as $index => $row) {
            $issues = [];
            foreach ($identifiersByRow[$index] ?? [] as $field => $value) {
                $label = self::duplicateFieldLabel($field);
                if (isset($seen[$field][$value])) {
                    $issues[] = sprintf('%s duplicates row %d in this file', $label, $seen[$field][$value]);
                } else {
                    $seen[$field][$value] = $index + 2;
                }

                if (isset($existing[$field][$value])) {
                    $issues[] = sprintf('%s already exists for %s', $label, $existing[$field][$value]);
                }
            }

            if ($issues !== []) {
                $row['__import_duplicate_issues'] = array_values(array_unique($issues));
            }
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private static function duplicateIdentifiersForRow(array $row): array
    {
        $email = self::cleanContactImportCell(self::value($row, ['email', 'email_address']));
        $email = $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? Str::lower($email)
            : null;

        return array_filter([
            'email' => $email,
            'phone_number' => self::normalizePhoneNumber(self::cleanContactImportCell(self::value($row, ['phone_number', 'phone', 'mobile']))),
            'sss_number' => self::formatGovernmentIdFromImport(GovernmentIdFormatter::TYPE_SSS, self::value($row, [
                'sss_number', 'sss', 'sss_no', 'sss_id', 'sss_umid', 'social_security_number', 'social_security_system_number',
            ])),
            'philhealth_number' => self::formatGovernmentIdFromImport(GovernmentIdFormatter::TYPE_PHILHEALTH, self::value($row, [
                'philhealth_number', 'philhealth', 'philhealth_no', 'philhealth_id', 'phic_number', 'phic_id',
            ])),
            'pagibig_number' => self::formatGovernmentIdFromImport(GovernmentIdFormatter::TYPE_PAGIBIG, self::value($row, [
                'pag_ibig_number', 'pagibig_number', 'pagibig', 'pag_ibig', 'pag_ibig_no', 'pagibig_no', 'hdmf_number', 'hdmf_id',
            ])),
            'tin_number' => self::formatGovernmentIdFromImport(GovernmentIdFormatter::TYPE_TIN, self::value($row, [
                'tin_number', 'tin', 'tin_id', 'bir_tin', 'bir_tin_number',
            ])),
        ], fn ($value) => is_string($value) && trim($value) !== '');
    }

    /**
     * @param  array<string, array<string, bool>>  $pending
     * @return array<string, array<string, string>>
     */
    private static function existingDuplicateIdentifiers(array $pending): array
    {
        $existing = [
            'email' => [],
            'phone_number' => [],
            'sss_number' => [],
            'philhealth_number' => [],
            'pagibig_number' => [],
            'tin_number' => [],
        ];

        $emails = array_keys($pending['email'] ?? []);
        if ($emails !== []) {
            User::query()
                ->whereNotNull('email')
                ->whereIn(DB::raw('LOWER(email)'), $emails)
                ->get(['id', 'name', 'email'])
                ->each(function (User $user) use (&$existing): void {
                    $existing['email'][Str::lower((string) $user->email)] = self::employeeLabel($user);
                });
        }

        $phones = array_keys($pending['phone_number'] ?? []);
        if ($phones !== []) {
            User::query()
                ->whereIn('phone_number', $phones)
                ->get(['id', 'name', 'phone_number'])
                ->each(function (User $user) use (&$existing): void {
                    $existing['phone_number'][(string) $user->phone_number] = self::employeeLabel($user);
                });
        }

        foreach (['sss_number', 'philhealth_number', 'pagibig_number', 'tin_number'] as $field) {
            $values = array_keys($pending[$field] ?? []);
            if ($values === []) {
                continue;
            }
            EmployeeGovernmentId::query()
                ->with(['user:id,name'])
                ->whereIn($field, $values)
                ->get(['user_id', $field])
                ->each(function (EmployeeGovernmentId $record) use (&$existing, $field): void {
                    $existing[$field][(string) $record->{$field}] = $record->user instanceof User
                        ? self::employeeLabel($record->user)
                        : 'an existing employee';
                });
        }

        return $existing;
    }

    private static function employeeLabel(User $user): string
    {
        $name = trim((string) $user->name);

        return $name !== '' ? $name : 'employee #'.((int) $user->id);
    }

    private static function duplicateFieldLabel(string $field): string
    {
        return match ($field) {
            'email' => 'Email',
            'phone_number' => 'Phone number',
            'sss_number' => 'SSS number',
            'philhealth_number' => 'PhilHealth number',
            'pagibig_number' => 'Pag-IBIG number',
            'tin_number' => 'TIN number',
            default => Str::headline($field),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $aliases
     */
    private static function value(array $row, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            $candidate = self::normalizeHeaderKey($alias);
            foreach ($row as $key => $value) {
                if (self::normalizeHeaderKey((string) $key) === $candidate) {
                    return $value;
                }
            }
        }

        return null;
    }

    private static function normalizeHeaderKey(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && ! preg_match('/\s/', $value) && preg_match('/[a-z][A-Z]/', $value)) {
            $value = Str::snake($value);
        }
        $v = Str::lower($value);
        $v = str_replace(['-', '(', ')', '/', '.'], ' ', $v);
        $v = preg_replace('/\s+/', '_', (string) $v);

        return trim((string) $v, '_');
    }

    private static function clean(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function cleanContactImportCell(mixed $value): ?string
    {
        $text = self::clean($value);
        if ($text === null) {
            return null;
        }
        if (in_array(Str::lower($text), ['n/a', '#n/a', '#na', '-', '--', 'none', 'null', '(none)', 'tbd', 'tba', 'no email', 'no phone', 'na'], true)) {
            return null;
        }

        return $text;
    }

    private static function normalizePhoneNumber(mixed $value): ?string
    {
        $raw = self::clean($value);
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '639') && strlen($digits) === 12) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            return '+63'.substr($digits, 1);
        }
        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '+63'.$digits;
        }

        return null;
    }

    private static function formatGovernmentIdFromImport(string $type, mixed $raw): ?string
    {
        $value = self::clean($raw);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }

        return GovernmentIdFormatter::format($type, $digits);
    }
}
