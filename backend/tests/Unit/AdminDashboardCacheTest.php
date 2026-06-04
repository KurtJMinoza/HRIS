<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\AdminDashboardCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminDashboardCacheTest extends TestCase
{
    public function test_dashboard_cache_key_uses_user_company_and_segment_version(): void
    {
        Cache::flush();
        Cache::forever('admin_dashboard:version:7:summary', 3);

        $actor = \Mockery::mock(User::class)->makePartial();
        $actor->id = 42;
        $actor->company_id = 7;
        $actor->hr_role = 'admin_hr';
        $actor->role = 'admin';
        $actor->shouldReceive('getEffectiveCompanyId')->andReturn(7);

        $key = AdminDashboardCache::key($actor, 'summary', '2026-06-04');

        $this->assertSame('admin_dashboard:summary:42:7:2026-06-04:v3', $key);
    }

    public function test_invalidate_company_bumps_segment_version(): void
    {
        Cache::flush();

        $before = AdminDashboardCache::segmentVersion(9, 'requests');
        AdminDashboardCache::invalidateCompany(9, ['requests']);
        $after = AdminDashboardCache::segmentVersion(9, 'requests');

        $this->assertGreaterThan($before, $after);
    }
}
