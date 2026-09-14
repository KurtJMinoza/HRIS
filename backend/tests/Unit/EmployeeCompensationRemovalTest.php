<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\EmployeeCompensationController;
use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeCompensationRemovalTest extends TestCase
{
    private function skipUnlessCompensationTables(): void
    {
        if (! Schema::hasTable('employee_compensation_components') || ! Schema::hasTable('pay_components')) {
            $this->markTestSkipped('employee compensation tables not available');
        }
    }

    public function test_destroy_soft_removes_basic_salary_and_prevents_auto_backfill(): void
    {
        $this->skipUnlessCompensationTables();

        $user = User::factory()->create([
            'monthly_salary' => 80000,
            'monthly_rate' => 80000,
            'is_active' => true,
        ]);

        $master = PayComponent::query()->firstOrCreate(
            ['code' => 'BASIC_SALARY'],
            [
                'name' => 'Basic Salary',
                'type' => PayComponent::TYPE_EARNING,
                'category' => 'Basic Salary',
                'calculation_type' => PayComponent::CALC_FIXED,
                'default_value' => 0,
                'is_active' => true,
                'is_system_protected' => true,
            ]
        );

        $assignment = EmployeeCompensationComponent::query()->create([
            'user_id' => $user->id,
            'pay_component_id' => $master->id,
            'name' => 'Basic Salary',
            'code' => 'BASIC_SALARY',
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Basic Salary',
            'calculation_type' => PayComponent::CALC_FIXED,
            'value' => 80000,
            'is_active' => true,
            'is_custom' => false,
        ]);

        try {
            $controller = app(EmployeeCompensationController::class);
            $response = $controller->destroy(Request::create('/', 'DELETE'), $user->id, $assignment->id);
            $this->assertSame(200, $response->getStatusCode());

            $assignment->refresh();
            $this->assertFalse($assignment->is_active);
            $this->assertSame(
                EmployeeCompensationComponent::ASSIGNMENT_SOURCE_MANUAL_REMOVED,
                $assignment->metadata['assignment_source'] ?? null
            );

            $user->refresh();
            $this->assertNull($user->monthly_salary);

            $summary = app(PayrollCalculatorService::class)->buildEmployeeCompensationSummary($user->fresh(), [
                'cache' => false,
            ]);

            $this->assertSame(0.0, (float) ($summary['basic_salary'] ?? -1));
            $this->assertSame(0.0, (float) ($summary['totals']['gross_earnings'] ?? -1));
            $this->assertFalse(
                collect($summary['earnings'] ?? [])->contains(
                    fn (array $line) => strtoupper((string) ($line['code'] ?? '')) === 'BASIC_SALARY'
                )
            );
        } finally {
            EmployeeCompensationComponent::query()->where('user_id', $user->id)->forceDelete();
            $user->forceDelete();
        }
    }
}
