<?php

namespace App\Services;

use App\Models\EmployeeBankAccount;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

class BankPayrollExportService
{
    public const BANK_AUB = 'AUB';

    public function __construct(
        private readonly PayslipService $payslipService,
    ) {}

    /**
     * Payroll modules included in cutoff-wide bank exports.
     *
     * @return list<string>
     */
    public function exportPayrollModules(): array
    {
        return [
            PayrollBatchRun::MODULE_STANDARD,
            PayrollBatchRun::MODULE_CONSULTANT,
        ];
    }

    /** @var array<string, array{label:string, title_row:string, template?:string}> */
    private const BANK_DEFINITIONS = [
        self::BANK_AUB => [
            'label' => 'Asia United Bank (AUB)',
            'title_row' => 'AUB NetPay Upload File',
            'template' => 'aub_netpay_upload_template.xls',
        ],
    ];

    private const EXPORT_DATA_START_ROW = 4;

    /** @var list<string> */
    private const EXPORT_HEADER_ROW = ['Employee No.', 'Name', 'Account No.', 'Bank Code', 'Salary'];

    /**
     * @return array<string, array{label:string, title_row:string}>
     */
    public function supportedBanks(): array
    {
        return self::BANK_DEFINITIONS;
    }

    public function normalizeBankCode(string $bankCode): string
    {
        return strtoupper(trim($bankCode));
    }

    public function isSupportedBank(string $bankCode): bool
    {
        return array_key_exists($this->normalizeBankCode($bankCode), self::BANK_DEFINITIONS);
    }

    /**
     * Distinct finalized payroll cutoffs available for bank export (standard + consultant).
     *
     * @return list<array{key:string,from_date:string,to_date:string,company_count:int}>
     */
    public function listFinalizedCutoffs(): array
    {
        $runs = PayrollBatchRun::query()
            ->where('status', PayrollBatchRun::STATUS_FINALIZED)
            ->whereIn('payroll_module', $this->exportPayrollModules())
            ->whereNotNull('pay_period_start')
            ->whereNotNull('pay_period_end')
            ->orderByDesc('pay_period_end')
            ->orderByDesc('pay_period_start')
            ->get(['pay_period_start', 'pay_period_end', 'company_id']);

        /** @var array<string, array{from_date:string,to_date:string,company_ids:array<int, true>}> $grouped */
        $grouped = [];
        foreach ($runs as $run) {
            $start = $run->pay_period_start?->toDateString();
            $end = $run->pay_period_end?->toDateString();
            if ($start === null || $end === null) {
                continue;
            }

            $key = $start.'|'.$end;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'from_date' => $start,
                    'to_date' => $end,
                    'company_ids' => [],
                ];
            }

            if ($run->company_id !== null && (int) $run->company_id > 0) {
                $grouped[$key]['company_ids'][(int) $run->company_id] = true;
            }
        }

        return array_values(array_map(
            static fn (array $item): array => [
                'key' => $item['from_date'].'|'.$item['to_date'],
                'from_date' => $item['from_date'],
                'to_date' => $item['to_date'],
                'company_count' => count($item['company_ids']),
            ],
            $grouped
        ));
    }

    /**
     * Build a bank export for every finalized payroll company sharing the anchor run's cutoff.
     *
     * @return array{
     *   bank:string,
     *   bank_label:string,
     *   title_row:string,
     *   rows:list<array{employee_no:string,name:string,account_number:string,bank_code:string,salary:float}>,
     *   eligible_count:int,
     *   excluded_count:int,
     *   excluded:array{missing_bank:int,invalid_bank:int,zero_net_pay:int},
     *   total_salary:float,
     *   pay_period_start:string,
     *   pay_period_end:string,
     *   company_count:int,
     *   anchor_run:PayrollBatchRun
     * }
     */
    public function buildExportPayloadForCutoff(PayrollBatchRun $anchorRun, string $bankCode): array
    {
        $this->assertFinalizedRun($anchorRun);

        $start = $anchorRun->pay_period_start?->toDateString();
        $end = $anchorRun->pay_period_end?->toDateString();
        if ($start === null || $end === null) {
            throw new \RuntimeException('Pay period dates are required for Bank Payroll Export.');
        }

        $payload = $this->buildExportPayloadForCutoffDates($start, $end, $bankCode);
        $payload['anchor_run'] = $anchorRun;

        return $payload;
    }

    /**
     * @return array{
     *   bank:string,
     *   bank_label:string,
     *   title_row:string,
     *   rows:list<array{employee_no:string,name:string,account_number:string,bank_code:string,salary:float}>,
     *   eligible_count:int,
     *   excluded_count:int,
     *   excluded:array{missing_bank:int,invalid_bank:int,zero_net_pay:int},
     *   total_salary:float,
     *   pay_period_start:string,
     *   pay_period_end:string,
     *   company_count:int,
     *   company_scope:string
     * }
     */
    public function buildExportPayloadForCutoffDates(string $start, string $end, string $bankCode): array
    {
        $bankCode = $this->normalizeBankCode($bankCode);
        if (! $this->isSupportedBank($bankCode)) {
            throw new \RuntimeException('Unsupported bank export template.');
        }

        $start = trim($start);
        $end = trim($end);
        if ($start === '' || $end === '') {
            throw new \RuntimeException('Pay period cutoff dates are required for Bank Payroll Export.');
        }

        $runs = $this->finalizedRunsForBankExportCutoff($start, $end);
        if ($runs->isEmpty()) {
            throw new \RuntimeException('No finalized payroll runs were found for this pay period cutoff.');
        }

        $payslips = $this->finalizedPayslipsForRuns($runs);
        if ($payslips->isEmpty()) {
            throw new \RuntimeException('No finalized payslips were found for this pay period cutoff.');
        }

        $bankAccounts = EmployeeBankAccount::query()
            ->whereIn('user_id', $payslips->pluck('user_id')->filter()->unique()->values())
            ->get()
            ->keyBy('user_id');

        $rows = [];
        $excluded = [
            'missing_bank' => 0,
            'invalid_bank' => 0,
            'zero_net_pay' => 0,
        ];

        foreach ($payslips as $payslip) {
            $employee = $payslip->employee;
            if (! $employee instanceof User) {
                $excluded['missing_bank']++;

                continue;
            }

            $netPay = $this->exportNetPay($payslip);
            if ($netPay <= 0) {
                $excluded['zero_net_pay']++;

                continue;
            }

            $bankAccount = $bankAccounts->get((int) $employee->id);
            if (! $this->isEligibleBankAccount($bankAccount, $bankCode)) {
                if ($bankAccount === null
                    || trim((string) ($bankAccount->bank_code ?? '')) === ''
                    || trim((string) ($bankAccount->account_number ?? '')) === '') {
                    $excluded['missing_bank']++;
                } else {
                    $excluded['invalid_bank']++;
                }

                continue;
            }

            $rows[] = [
                'employee_no' => trim((string) ($employee->employee_code ?? '')),
                'name' => self::formatAubEmployeeName($employee),
                'account_number' => self::formatExportAccountNumber($bankAccount->account_number),
                'bank_code' => $bankCode,
                'salary' => $netPay,
            ];
        }

        $this->sortRowsAlphabetically($rows);

        if ($rows === []) {
            throw new \RuntimeException('No employees with valid '.$bankCode.' bank accounts and positive net pay were found for this finalized pay period cutoff.');
        }

        $bankDefinition = self::BANK_DEFINITIONS[$bankCode];
        $companyCount = $payslips
            ->pluck('company_id')
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->unique()
            ->count();

        return [
            'bank' => $bankCode,
            'bank_label' => (string) $bankDefinition['label'],
            'title_row' => (string) $bankDefinition['title_row'],
            'rows' => $rows,
            'eligible_count' => count($rows),
            'excluded_count' => array_sum($excluded),
            'excluded' => $excluded,
            'total_salary' => round(array_sum(array_column($rows, 'salary')), 2),
            'pay_period_start' => $start,
            'pay_period_end' => $end,
            'company_count' => $companyCount,
            'company_scope' => 'All Companies',
        ];
    }

    /**
     * @return array{filename:string, employee_count:int, write:callable(): void}
     */
    public function xlsForCutoffDates(string $start, string $end, string $bankCode): array
    {
        $payload = $this->buildExportPayloadForCutoffDates($start, $end, $bankCode);

        return [
            'filename' => $this->cutoffFilename($payload, 'xls'),
            'employee_count' => $payload['eligible_count'],
            'write' => fn () => $this->writeSpreadsheet($payload),
        ];
    }

    /**
     * @return array{filename:string, employee_count:int, write:callable(): void}
     */
    public function csvForCutoffDates(string $start, string $end, string $bankCode): array
    {
        $payload = $this->buildExportPayloadForCutoffDates($start, $end, $bankCode);

        return [
            'filename' => $this->cutoffFilename($payload, 'csv'),
            'employee_count' => $payload['eligible_count'],
            'write' => fn () => $this->writeCsv($payload),
        ];
    }

    /**
     * @return array{pdf:\Barryvdh\DomPDF\PDF, filename:string, employee_count:int}
     */
    public function pdfForCutoffDates(string $start, string $end, string $bankCode): array
    {
        $payload = $this->buildExportPayloadForCutoffDates($start, $end, $bankCode);
        $pdf = Pdf::loadView('reports.bank_payroll_export_pdf', $payload)
            ->setPaper('a4', 'landscape');

        return [
            'pdf' => $pdf,
            'filename' => $this->cutoffFilename($payload, 'pdf'),
            'employee_count' => $payload['eligible_count'],
        ];
    }

    /**
     * @return array{filename:string, employee_count:int, write:callable(): void}
     */
    public function xlsForCutoff(PayrollBatchRun $anchorRun, string $bankCode): array
    {
        $payload = $this->buildExportPayloadForCutoff($anchorRun, $bankCode);

        return [
            'filename' => $this->cutoffFilename($payload, 'xls'),
            'employee_count' => $payload['eligible_count'],
            'write' => fn () => $this->writeSpreadsheet($payload),
        ];
    }

    /**
     * @return array{filename:string, employee_count:int, write:callable(): void}
     */
    public function csvForCutoff(PayrollBatchRun $anchorRun, string $bankCode): array
    {
        $payload = $this->buildExportPayloadForCutoff($anchorRun, $bankCode);

        return [
            'filename' => $this->cutoffFilename($payload, 'csv'),
            'employee_count' => $payload['eligible_count'],
            'write' => fn () => $this->writeCsv($payload),
        ];
    }

    /**
     * @return array{pdf:\Barryvdh\DomPDF\PDF, filename:string, employee_count:int}
     */
    public function pdfForCutoff(PayrollBatchRun $anchorRun, string $bankCode): array
    {
        $payload = $this->buildExportPayloadForCutoff($anchorRun, $bankCode);
        $pdf = Pdf::loadView('reports.bank_payroll_export_pdf', $payload)
            ->setPaper('a4', 'landscape');

        return [
            'pdf' => $pdf,
            'filename' => $this->cutoffFilename($payload, 'pdf'),
            'employee_count' => $payload['eligible_count'],
        ];
    }

    public static function formatAubEmployeeName(User $user): string
    {
        $last = self::asciiUpper(trim((string) $user->last_name));
        $first = self::asciiUpper(trim((string) $user->first_name));
        $middle = self::asciiUpper(trim((string) $user->middle_name));
        $suffix = self::asciiUpper(trim((string) $user->suffix));

        if ($last !== '' && $first !== '') {
            $parts = array_values(array_filter([$last, $first, $middle !== '' ? $middle : null, $suffix !== '' ? $suffix : null]));

            return implode(' ', $parts);
        }

        return self::asciiUpper(trim((string) $user->name));
    }

    public function isEligibleBankAccount(?EmployeeBankAccount $bankAccount, string $bankCode): bool
    {
        if (! $bankAccount instanceof EmployeeBankAccount) {
            return false;
        }

        $code = strtoupper(trim((string) ($bankAccount->bank_code ?? '')));
        if ($code !== $this->normalizeBankCode($bankCode)) {
            return false;
        }

        $accountNumber = self::formatExportAccountNumber($bankAccount->account_number ?? '');

        return strlen($accountNumber) === 12;
    }

    public static function formatExportAccountNumber(mixed $accountNumber): string
    {
        $raw = trim((string) $accountNumber);
        if ($raw === '') {
            return '';
        }

        if (is_int($accountNumber) || is_float($accountNumber)) {
            $raw = sprintf('%.0f', (float) $accountNumber);
        } elseif (preg_match('/^[+-]?\d+(?:\.\d+)?[eE][+-]?\d+$/', $raw)) {
            $raw = sprintf('%.0f', (float) $raw);
        }

        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /**
     * @param  list<array{employee_no:string,name:string,account_number:string,bank_code:string,salary:float}>  $rows
     */
    public function sortRowsAlphabetically(array &$rows): void
    {
        usort($rows, static function (array $a, array $b): int {
            $nameCompare = strcasecmp($a['name'], $b['name']);
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return strcasecmp($a['employee_no'], $b['employee_no']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeSpreadsheet(array $payload): void
    {
        $spreadsheet = $this->buildExportSpreadsheet($payload);

        (new Xls($spreadsheet))->save('php://output');
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildExportSpreadsheet(array $payload): Spreadsheet
    {
        $bankCode = $this->normalizeBankCode((string) ($payload['bank'] ?? self::BANK_AUB));
        $spreadsheet = $this->loadExportSpreadsheetTemplate($bankCode);
        $spreadsheet->getProperties()
            ->setCreator((string) config('app.name', 'HR'))
            ->setTitle((string) ($payload['title_row'] ?? 'Bank Payroll Export'));

        $sheet = $spreadsheet->getSheet(0);
        $sheet->setCellValue('A1', (string) ($payload['title_row'] ?? ''));
        $sheet->fromArray(self::EXPORT_HEADER_ROW, null, 'A3');
        $this->clearExportDataRows($sheet);
        $this->writeExportDataRows($sheet, $payload['rows'] ?? []);

        return $spreadsheet;
    }

    private function loadExportSpreadsheetTemplate(string $bankCode): Spreadsheet
    {
        $template = self::BANK_DEFINITIONS[$bankCode]['template'] ?? null;
        if (is_string($template) && $template !== '') {
            $path = resource_path('templates/'.$template);
            if (is_readable($path)) {
                return IOFactory::load($path);
            }
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        return $spreadsheet;
    }

    /**
     * @param  list<array{employee_no:string,name:string,account_number:string,bank_code:string,salary:float}>  $rows
     */
    private function writeExportDataRows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows): void
    {
        $sheet->getStyle('C:C')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $rowIndex = self::EXPORT_DATA_START_ROW;
        foreach ($rows as $row) {
            $accountNumber = self::formatExportAccountNumber($row['account_number'] ?? '');
            $accountCell = 'C'.$rowIndex;
            // ponytail: AUB upload template keeps Employee No. and Bank Code headers but leaves data cells blank.
            $sheet->setCellValue('A'.$rowIndex, '');
            $sheet->setCellValue('B'.$rowIndex, (string) ($row['name'] ?? ''));
            $sheet->getStyle($accountCell)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->setCellValueExplicit($accountCell, $accountNumber, DataType::TYPE_STRING);
            $sheet->setCellValue('D'.$rowIndex, '');
            $sheet->setCellValue('E'.$rowIndex, (float) ($row['salary'] ?? 0));
            $sheet->getStyle('E'.$rowIndex)
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $rowIndex++;
        }
    }

    private function clearExportDataRows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $highestRow = max(self::EXPORT_DATA_START_ROW, (int) $sheet->getHighestRow());
        for ($rowIndex = self::EXPORT_DATA_START_ROW; $rowIndex <= $highestRow; $rowIndex++) {
            foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
                $sheet->setCellValue($column.$rowIndex, null);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeCsv(array $payload): void
    {
        $spreadsheet = $this->buildExportSpreadsheet($payload);
        $sheet = $spreadsheet->getActiveSheet();

        $out = fopen('php://output', 'w');
        if ($out === false) {
            $spreadsheet->disconnectWorksheets();

            return;
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$payload['title_row']]);
        fputcsv($out, []);
        fputcsv($out, self::EXPORT_HEADER_ROW);

        foreach ($payload['rows'] as $row) {
            $accountNumber = self::formatExportAccountNumber($row['account_number'] ?? '');
            fputcsv($out, [
                '',
                (string) ($row['name'] ?? ''),
                self::accountNumberForCsvField($accountNumber),
                '',
                number_format((float) ($row['salary'] ?? 0), 2, '.', ''),
            ]);
        }

        fclose($out);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * Excel auto-converts long numeric CSV fields to scientific notation unless the
     * value is imported as text. A leading tab is invisible in the cell but keeps
     * the full 12-digit account number visible as plain digits.
     */
    public static function accountNumberForCsvField(string $accountNumber): string
    {
        return $accountNumber === '' ? '' : "\t".$accountNumber;
    }

    private static function csvEscape(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function assertFinalizedRun(PayrollBatchRun $run): void
    {
        if ((string) $run->status !== PayrollBatchRun::STATUS_FINALIZED) {
            throw new \RuntimeException('Bank Payroll Export is only available for finalized payroll runs.');
        }
    }

    /**
     * @return Collection<int, PayrollBatchRun>
     */
    private function finalizedRunsForBankExportCutoff(string $start, string $end): Collection
    {
        $runs = PayrollBatchRun::query()
            ->where('status', PayrollBatchRun::STATUS_FINALIZED)
            ->whereIn('payroll_module', $this->exportPayrollModules())
            ->whereDate('pay_period_start', $start)
            ->whereDate('pay_period_end', $end)
            ->orderByDesc('id')
            ->get();

        /** @var array<string, PayrollBatchRun> $latestByScope */
        $latestByScope = [];
        foreach ($runs as $run) {
            $scopeKey = strtolower(trim((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD)))
                .':'.((int) ($run->company_id ?? 0)).':'.$start.':'.$end;
            if (! isset($latestByScope[$scopeKey])) {
                $latestByScope[$scopeKey] = $run;
            }
        }

        return collect(array_values($latestByScope))
            ->sortBy(fn (PayrollBatchRun $run): string => sprintf(
                '%04d-%010d',
                (int) ($run->company_id ?? 0),
                (int) $run->id
            ))
            ->values();
    }

    /**
     * @param  Collection<int, PayrollBatchRun>  $runs
     * @return Collection<int, Payslip>
     */
    private function finalizedPayslipsForRuns(Collection $runs): Collection
    {
        if ($runs->isEmpty()) {
            return collect();
        }

        /** @var array<string, Payslip> $mergedByKey */
        $mergedByKey = [];
        foreach ($runs as $run) {
            if (! $run instanceof PayrollBatchRun) {
                continue;
            }

            $query = Payslip::query()
                ->with([
                    'employee:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id',
                ])
                ->where('payroll_batch_run_id', (int) $run->id)
                ->whereNull('voided_at')
                ->where('period_slot', 0)
                ->whereIn('payroll_module', $this->exportPayrollModules())
                ->whereIn('status', Payslip::lockingStatuses())
                ->whereNotNull('snapshot')
                ->orderByDesc('id');

            $companyId = (int) ($run->company_id ?? 0);
            if ($companyId > 0) {
                $query->where('company_id', $companyId);
            }

            $module = strtolower(trim((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD)));
            foreach ($query->get()->unique('user_id') as $payslip) {
                $dedupeKey = (int) $payslip->user_id.'|'.$module;
                $mergedByKey[$dedupeKey] = $payslip;
            }
        }

        return collect(array_values($mergedByKey));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cutoffFilename(array $payload, string $extension): string
    {
        $start = str_replace('-', '', (string) ($payload['pay_period_start'] ?? 'start'));
        $end = str_replace('-', '', (string) ($payload['pay_period_end'] ?? 'end'));

        return sprintf(
            'Bank_Payroll_Export_%s_All_Companies_%s_%s.%s',
            $payload['bank'],
            $start,
            $end,
            $extension
        );
    }

    /**
     * AUB salary is the frozen finalized net (same number as payroll report / finalize).
     */
    private function exportNetPay(Payslip $payslip): float
    {
        $totals = $this->payslipService->payslipTotalsForDisplay($payslip);

        return round((float) ($totals['net_pay'] ?? 0), 2);
    }

    private static function asciiUpper(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = str_replace(['Ñ', 'ñ'], ['N', 'n'], $value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtoupper(trim(is_string($ascii) ? $ascii : $value));
    }
}
