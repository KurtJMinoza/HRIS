<?php

namespace Tests\Unit;

use App\Models\EmployeeBankAccount;
use App\Models\User;
use App\Services\BankPayrollExportService;
use Tests\TestCase;

class BankPayrollExportServiceTest extends TestCase
{
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

    public function test_is_eligible_bank_account_requires_aub_and_twelve_digits(): void
    {
        $service = new BankPayrollExportService;

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
        $service = new BankPayrollExportService;
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
}
