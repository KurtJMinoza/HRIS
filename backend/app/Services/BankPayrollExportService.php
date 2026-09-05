<?php

namespace App\Services;

use App\Models\EmployeeBankAccount;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BankPayrollExportService
{
    public const BANK_AUB = 'AUB';

    /** @var array<string, array{label:string, title_row:string}> */
    private const BANK_DEFINITIONS = [
        self::BANK_AUB => [
            'label' => 'Asia United Bank (AUB)',
            'title_row' => 'AUB NetPay Upload File',
        ],
    ];

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
     * Distinct finalized regular-payroll cutoffs available for bank export.
     *
     * @return list<array{key:string,from_date:string,to_date:string,company_count:int}>
     */
    public function listFinalizedCutoffs(): array
    {
        $runs = PayrollBatchRun::query()
            ->where('status', PayrollBatchRun::STATUS_FINALIZED)
            ->where('payroll_module', PayrollBatchRun::MODULE_STANDARD)
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
     * Build a bank export for every finalized regular-payroll company sharing the anchor run's cutoff.
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

        $runs = $this->finalizedStandardRunsForCutoff($start, $end);
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

            $netPay = round((float) ($payslip->net_pay ?? 0), 2);
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
                'account_number' => (string) $bankAccount->account_number,
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
    public function xlsxForCutoffDates(string $start, string $end, string $bankCode): array
    {
        $payload = $this->buildExportPayloadForCutoffDates($start, $end, $bankCode);

        return [
            'filename' => $this->cutoffFilename($payload, 'xlsx'),
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
    public function xlsxForCutoff(PayrollBatchRun $anchorRun, string $bankCode): array
    {
        $payload = $this->buildExportPayloadForCutoff($anchorRun, $bankCode);

        return [
            'filename' => $this->cutoffFilename($payload, 'xlsx'),
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

        $accountNumber = preg_replace('/\D+/', '', (string) ($bankAccount->account_number ?? '')) ?? '';

        return strlen($accountNumber) === 12;
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
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator((string) config('app.name', 'HR'))
            ->setTitle($payload['title_row']);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bank Payroll');
        $sheet->setCellValue('A1', $payload['title_row']);
        $sheet->fromArray(
            ['Employee No.', 'Name', 'Account No.', 'Bank Code', 'Salary'],
            null,
            'A3'
        );

        $rowIndex = 4;
        foreach ($payload['rows'] as $row) {
            $employeeNo = (string) ($row['employee_no'] ?? '');
            $accountNumber = (string) ($row['account_number'] ?? '');
            $sheet->setCellValueExplicit('A'.$rowIndex, $employeeNo, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$rowIndex, $row['name']);
            $sheet->setCellValueExplicit('C'.$rowIndex, $accountNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D'.$rowIndex, (string) ($row['bank_code'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('E'.$rowIndex, $row['salary']);
            $sheet->getStyle('E'.$rowIndex)
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $rowIndex++;
        }

        $lastRow = max(4, $rowIndex - 1);
        $sheet->getStyle('A4:A'.$lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('C4:C'.$lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('D4:D'.$lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        (new Xlsx($spreadsheet))->save('php://output');
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeCsv(array $payload): void
    {
        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$payload['title_row']]);
        fputcsv($out, []);
        fputcsv($out, ['Employee No.', 'Name', 'Account No.', 'Bank Code', 'Salary']);
        foreach ($payload['rows'] as $row) {
            fputcsv($out, [
                $row['employee_no'],
                $row['name'],
                $row['account_number'],
                $row['bank_code'],
                number_format($row['salary'], 2, '.', ''),
            ]);
        }

        fclose($out);
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
    private function finalizedStandardRunsForCutoff(string $start, string $end): Collection
    {
        return PayrollBatchRun::query()
            ->where('status', PayrollBatchRun::STATUS_FINALIZED)
            ->where('payroll_module', PayrollBatchRun::MODULE_STANDARD)
            ->whereDate('pay_period_start', $start)
            ->whereDate('pay_period_end', $end)
            ->orderBy('company_id')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (PayrollBatchRun $run): string => (string) ((int) ($run->company_id ?? 0)).':'.$start.':'.$end)
            ->values();
    }

    /**
     * @param  Collection<int, PayrollBatchRun>  $runs
     * @return Collection<int, Payslip>
     */
    private function finalizedPayslipsForRuns(Collection $runs): Collection
    {
        $runIds = $runs->pluck('id')->filter()->values();
        if ($runIds->isEmpty()) {
            return collect();
        }

        return Payslip::query()
            ->with([
                'employee:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id',
            ])
            ->whereIn('payroll_batch_run_id', $runIds)
            ->whereNull('voided_at')
            ->where('period_slot', 0)
            ->where('payroll_module', PayrollBatchRun::MODULE_STANDARD)
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereNotNull('snapshot')
            ->orderByDesc('id')
            ->get()
            ->unique('user_id')
            ->values();
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
