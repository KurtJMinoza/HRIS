<?php

namespace Tests\Unit;

use App\Services\PayrollComputationService;
use ReflectionMethod;
use Tests\TestCase;

class ConsultantAllowanceProrationTest extends TestCase
{
    public function test_consultant_attendance_proration_exposes_allowance_payable_day_units(): void
    {
        $service = app(PayrollComputationService::class);
        $method = new ReflectionMethod(PayrollComputationService::class, 'consultantAttendanceProration');
        $method->setAccessible(true);

        /** @var array<string, mixed> $proration */
        $proration = $method->invoke($service, 15);

        $this->assertSame(15.0, (float) ($proration['scheduled_workdays'] ?? 0));
        $this->assertSame(15.0, (float) ($proration['payable_day_units'] ?? 0));
        $this->assertSame(15.0, (float) data_get($proration, 'allowance.payable_day_units'));
        $this->assertSame(15.0, (float) data_get($proration, 'allowance.worked_day_units'));
        $this->assertSame('consultant_auto_present', (string) data_get($proration, 'allowance.proration_basis'));
    }
}
