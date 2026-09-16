<?php

namespace Tests\Unit;

use App\Models\EmployeeBankAccount;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\BankPayrollExportService;
use Tests\TestCase;

class BankPayrollExportServiceTest extends TestCase
{
    public function test_export_payroll_modules_include_standard_and_consultant(): void
    {
        $service = app(BankPayrollExportService::class);

        $this->assertSame(
            [PayrollBatchRun::MODULE_STANDARD, PayrollBatchRun::MODULE_CONSULTANT],
            $service->exportPayrollModules()
        );
    }

    public function test_format_aub_employee_name_uses_last_first_order(): void
    {
        $user = new User([
            'first_name' => 'Mark Dennis',
            'last_name' => 'Acaso',
            'middle_name' => '',
            'suffix' => '',
        ]);

        $this->assertSame('ACASO MARK DENNIS', BankPayrollExportService::formatAubEmployeeName($user));
    }

    public function test_format_aub_employee_name_includes_suffix(): void
    {
        $user = new User([
            'first_name' => 'Renante',
            'last_name' => 'Bayal',
            'middle_name' => '',
            'suffix' => 'Jr',
        ]);

        $this->assertSame('BAYAL RENANTE JR', BankPayrollExportService::formatAubEmployeeName($user));
    }

    public function test_format_export_account_number_preserves_twelve_digits_without_scientific_notation(): void
    {
        $this->assertSame('934105106070', BankPayrollExportService::formatExportAccountNumber('934105106070'));
        $this->assertSame('934105106070', BankPayrollExportService::formatExportAccountNumber(934105106070));
        $this->assertSame('912312345678', BankPayrollExportService::formatExportAccountNumber('9.12312345678E+11'));
        $this->assertSame('912312345678', BankPayrollExportService::formatExportAccountNumber(912312345678));
    }

    public function test_account_number_for_csv_field_prefixes_tab_for_excel_text_import(): void
    {
        $this->assertSame("\t934105099758", BankPayrollExportService::accountNumberForCsvField('934105099758'));
        $this->assertSame('', BankPayrollExportService::accountNumberForCsvField(''));
    }

    public function test_is_eligible_bank_account_requires_aub_and_twelve_digits(): void
    {
        $service = app(BankPayrollExportService::class);

        $valid = new EmployeeBankAccount([
            'bank_code' => 'AUB',
            'account_number' => '934105106070',
        ]);
        $invalidCode = new EmployeeBankAccount([
            'bank_code' => 'BDO',
            'account_number' => '934105106070',
        ]);
        $invalidNumber = new EmployeeBankAccount([
            'bank_code' => 'AUB',
            'account_number' => '12345',
        ]);

        $this->assertTrue($service->isEligibleBankAccount($valid, BankPayrollExportService::BANK_AUB));
        $this->assertFalse($service->isEligibleBankAccount($invalidCode, BankPayrollExportService::BANK_AUB));
        $this->assertFalse($service->isEligibleBankAccount($invalidNumber, BankPayrollExportService::BANK_AUB));
        $this->assertFalse($service->isEligibleBankAccount(null, BankPayrollExportService::BANK_AUB));
    }

    public function test_sort_rows_alphabetically_by_name(): void
    {
        $service = app(BankPayrollExportService::class);
        $rows = [
            ['employee_no' => '2', 'name' => 'ZARA ANA', 'account_number' => '934105106070', 'bank_code' => 'AUB', 'salary' => 100.0],
            ['employee_no' => '1', 'name' => 'ABELARDE ARRON', 'account_number' => '934105106071', 'bank_code' => 'AUB', 'salary' => 200.0],
            ['employee_no' => '3', 'name' => 'MARTIN BEN', 'account_number' => '934105106072', 'bank_code' => 'AUB', 'salary' => 300.0],
        ];

        $service->sortRowsAlphabetically($rows);

        $this->assertSame('ABELARDE ARRON', $rows[0]['name']);
        $this->assertSame('MARTIN BEN', $rows[1]['name']);
        $this->assertSame('ZARA ANA', $rows[2]['name']);
    }

    public function test_export_net_pay_uses_display_totals_when_display_diverges_from_stored_column(): void
    {
        $payslip = new Payslip([
            'net_pay' => 9478.25,
            'snapshot' => [
                'summary' => [
                    'display_gross_pay' => 10018.25,
                    'display_net_pay' => 10018.25,
                    'daily_computation_earning_lines' => [
                        ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 9478.25, 'display_amount' => 10018.25],
                    ],
                    'payslip_earning_lines' => [],
                    'payslip_deduction_lines' => [],
                    'payslip_custom_deduction_lines' => [],
                ],
            ],
        ]);

        $method = new \ReflectionMethod(BankPayrollExportService::class, 'exportNetPay');
        $method->setAccessible(true);
        $netPay = $method->invoke(app(BankPayrollExportService::class), $payslip);

        $this->assertSame(10018.25, $netPay);
    }

    public function test_export_net_pay_matches_line_totals_when_display_is_aligned(): void
    {
        $payslip = new Payslip([
            'net_pay' => 6230.77,
            'snapshot' => [
                'summary' => [
                    'display_gross_pay' => 6230.77,
                    'display_net_pay' => 6230.77,
                    'daily_computation_earning_lines' => [
                        ['key' => 'regular_pay', 'label' => 'Regular pay', 'amount' => 5538.48],
                        ['key' => 'attendance_premium', 'label' => 'Attendance premiums', 'amount' => 692.29],
                    ],
                    'payslip_deduction_lines' => [],
                    'payslip_custom_deduction_lines' => [],
                ],
            ],
        ]);

        $method = new \ReflectionMethod(BankPayrollExportService::class, 'exportNetPay');
        $method->setAccessible(true);
        $netPay = $method->invoke(app(BankPayrollExportService::class), $payslip);

        $this->assertSame(6230.77, $netPay);
    }
}
