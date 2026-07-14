<?php

namespace Tests\Unit;

use App\Services\EmployeeEvaluationResultService;
use Tests\TestCase;

class EmployeeEvaluationResultServiceTest extends TestCase
{
    public function test_normalize_mode_defaults_to_within_period(): void
    {
        $service = app(EmployeeEvaluationResultService::class);

        $this->assertSame(
            EmployeeEvaluationResultService::MODE_WITHIN_PERIOD,
            $service->normalizeMode(null),
        );
        $this->assertSame(
            EmployeeEvaluationResultService::MODE_LATEST_AS_OF_PERIOD_END,
            $service->normalizeMode('latest_as_of_period_end'),
        );
    }

    public function test_empty_employee_ids_return_empty_collection(): void
    {
        $service = app(EmployeeEvaluationResultService::class);
        $results = $service->getLatestApplicableResultsForEmployees(
            [],
            '2026-07-01',
            '2026-07-14',
        );

        $this->assertTrue($results->isEmpty());
    }
}
