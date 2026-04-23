<?php

namespace App\Services;

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
            'rows' => $rows,
            'row_count' => count($rows),
            'column_count' => count($headers),
        ];
    }
}
