<?php

namespace Tests\Unit;

use App\Services\AdminAttendanceCacheService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminAttendanceCacheServiceTest extends TestCase
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

    public function test_remember_reports_cache_hit(): void
    {
        $key = 'attendance:test:list';
        $calls = 0;

        $first = AdminAttendanceCacheService::remember($key, 45, function () use (&$calls): array {
            $calls++;

            return ['rows' => [['attendance_id' => '1:2026-06-12']]];
        });
        $second = AdminAttendanceCacheService::remember($key, 45, function () use (&$calls): array {
            $calls++;

            return ['rows' => []];
        });

        $this->assertFalse($first['cache_hit']);
        $this->assertTrue($second['cache_hit']);
        $this->assertSame(1, $calls);
    }

    public function test_list_key_changes_when_filters_change(): void
    {
        $base = [
            'company_id' => 1,
            'branch_id' => 2,
            'page' => 1,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-12',
        ];

        $a = AdminAttendanceCacheService::listKey($base);
        $b = AdminAttendanceCacheService::listKey(array_merge($base, ['search' => 'john']));

        $this->assertNotSame($a, $b);
    }

    public function test_parse_attendance_id_supports_multiple_formats(): void
    {
        $colon = AdminAttendanceCacheService::parseAttendanceId('42:2026-06-12');
        $att = AdminAttendanceCacheService::parseAttendanceId('ATT-42-20260612');

        $this->assertSame(['employee_id' => 42, 'date' => '2026-06-12'], $colon);
        $this->assertSame(['employee_id' => 42, 'date' => '2026-06-12'], $att);
    }

    public function test_invalidate_affected_bumps_list_and_detail_versions(): void
    {
        $beforeList = AdminAttendanceCacheService::listKey(['company_id' => 1, 'branch_id' => 0, 'page' => 1]);
        $beforeDetail = AdminAttendanceCacheService::detailKey('9:2026-06-12');

        AdminAttendanceCacheService::put($beforeDetail, ['detail' => true], 300);
        AdminAttendanceCacheService::invalidateAffected(9, '2026-06-12');

        $afterList = AdminAttendanceCacheService::listKey(['company_id' => 1, 'branch_id' => 0, 'page' => 1]);
        $afterDetail = AdminAttendanceCacheService::detailKey('9:2026-06-12');

        $this->assertNotSame($beforeList, $afterList);
        $this->assertNotSame($beforeDetail, $afterDetail);
        $this->assertNull(AdminAttendanceCacheService::get($beforeDetail));
    }
}
