<?php

namespace Tests\Unit;

use App\Services\EmployeeRequestCacheService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EmployeeRequestCacheServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'cache.attendance_store' => null,
        ]);
        Cache::flush();
    }

    public function test_remember_reports_cache_hit_and_reuses_payload(): void
    {
        $service = app(EmployeeRequestCacheService::class);
        $calls = 0;
        $key = $service->leaveKey(12, 'list', ['page' => 1]);

        $first = $service->remember($key, 45, function () use (&$calls): array {
            $calls++;

            return ['leave_requests' => [['id' => 7]]];
        });
        $second = $service->remember($key, 45, function () use (&$calls): array {
            $calls++;

            return ['leave_requests' => []];
        });

        $this->assertFalse($first['cache_hit']);
        $this->assertTrue($second['cache_hit']);
        $this->assertSame(1, $calls);
        $this->assertSame(7, $second['payload']['leave_requests'][0]['id']);
    }

    public function test_employee_context_invalidation_changes_all_employee_keys(): void
    {
        $service = app(EmployeeRequestCacheService::class);
        $before = $service->attendanceDetailKey(21, '2026-06-12', 'both');

        $service->invalidateEmployeeContext(21);

        $after = $service->attendanceDetailKey(21, '2026-06-12', 'both');
        $this->assertNotSame($before, $after);
    }
}
