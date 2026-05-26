<?php

namespace Tests\Unit;

use App\Models\PayrollLine;
use App\Services\PayrollLinePersistService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PayrollLinePersistFinalizeTest extends TestCase
{
    public function test_map_snapshot_row_preserves_exact_draft_amount(): void
    {
        $persist = new PayrollLinePersistService;
        $reflection = new ReflectionClass(PayrollLinePersistService::class);
        $map = $reflection->getMethod('mapSnapshotLineToPayrollRow');
        $map->setAccessible(true);

        $row = $map->invoke($persist, [
                'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
                'label' => 'Lending Salary Deduction Every 30',
                'resolved_calculation_standard' => 'payroll_standard',
                'component_amount' => 1550.00,
                'amount' => 1550.00,
                'resolved_amount' => 1550.00,
            ], PayrollLine::TYPE_DEDUCTION, 'deduction', 0);

        $this->assertSame(1550.00, $row['amount']);
        $this->assertSame('payroll_standard', $row['calculation_standard']);
    }
}
