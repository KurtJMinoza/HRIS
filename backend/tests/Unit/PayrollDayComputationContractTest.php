<?php

namespace Tests\Unit;

use App\Contracts\ClearsPayrollRuntimeCaches;
use App\Contracts\PayrollBulkComputation;
use App\Contracts\PayrollDayComputation;
use App\Services\FinalizePayrollService;
use App\Services\PayrollComputationService;
use App\Services\PayslipService;
use Tests\TestCase;

class PayrollDayComputationContractTest extends TestCase
{
    public function test_finalize_payroll_service_can_flush_runtime_caches_via_contract(): void
    {
        $service = app(FinalizePayrollService::class);
        $engine = $this->resolvePayrollEngine($service, 'payrollComputation');

        $this->assertInstanceOf(PayrollDayComputation::class, $engine);
        $this->assertInstanceOf(ClearsPayrollRuntimeCaches::class, $engine);
        $this->assertInstanceOf(PayrollComputationService::class, $engine);

        $engine->flushRuntimeCaches();
        $this->assertTrue(true);
    }

    public function test_payslip_service_resolves_bulk_payroll_computation_contract(): void
    {
        $service = app(PayslipService::class);
        $engine = $this->resolvePayrollEngine($service, 'payrollComputation');

        $this->assertInstanceOf(PayrollBulkComputation::class, $engine);
        $this->assertInstanceOf(PayrollComputationService::class, $engine);
    }

    private function resolvePayrollEngine(object $service, string $property): PayrollComputationService
    {
        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        /** @var PayrollComputationService $engine */
        $engine = $prop->getValue($service);

        return $engine;
    }
}
