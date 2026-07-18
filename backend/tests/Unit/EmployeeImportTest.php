<?php

namespace Tests\Unit;

use App\Imports\EmployeeImport;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmployeeStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_create_status_sync_failure_does_not_retry_and_duplicate_employee(): void
    {
        $actor = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('assertCanCreateEmployeeInOrg')->once();

        $status = Mockery::mock(EmployeeStatusService::class);
        $status->shouldReceive('syncAutomaticEmploymentStatus')
            ->once()
            ->andThrow(new \RuntimeException('status sync failed'));
        $this->app->instance(EmployeeStatusService::class, $status);

        Log::shouldReceive('warning')
            ->once()
            ->with('Employee import post-create status sync failed', Mockery::on(
                fn (array $context): bool => ($context['row'] ?? null) === 2
                    && ($context['error'] ?? null) === 'status sync failed'
                    && isset($context['user_id'])
            ));

        $import = new EmployeeImport($actor, $scope, 'test-import-batch');
        $import->collection(collect([
            collect([
                'first_name' => 'Ailyn',
                'last_name' => 'Silana',
                'email' => 'ailyn.silana@example.com',
                'employment_status' => 'Probationary',
                'date_hired' => '2025-01-01',
            ]),
        ]));

        $summary = $import->summary();

        $this->assertSame(1, $summary['imported']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(1, User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('first_name', 'Ailyn')
            ->where('last_name', 'Silana')
            ->count());
    }
}
