<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\DashboardPendingCountsCache;
use App\Support\ReviewRequestCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RequestModulePerformanceCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_cache_uses_five_minute_ttl_and_module_key(): void
    {
        Cache::flush();

        $calls = 0;
        ReviewRequestCache::remember('leave', 42, function () use (&$calls) {
            $calls++;

            return ['id' => 42, 'status' => 'pending'];
        });

        $this->assertSame(1, $calls);
        $this->assertTrue(Cache::has('leave:review:42'));

        $cached = ReviewRequestCache::remember('leave', 42, function () use (&$calls) {
            $calls++;

            return ['id' => 42, 'status' => 'approved'];
        });

        $this->assertSame(1, $calls);
        $this->assertTrue($cached['cache_hit']);
        $this->assertSame('pending', $cached['payload']['status']);
    }

    public function test_review_cache_forget_many_clears_all_ids(): void
    {
        Cache::flush();
        Cache::put('leave:review:1', ['id' => 1], 300);
        Cache::put('leave:review:2', ['id' => 2], 300);

        ReviewRequestCache::forgetMany('leave', [1, 2]);

        $this->assertFalse(Cache::has('leave:review:1'));
        $this->assertFalse(Cache::has('leave:review:2'));
    }

    public function test_dashboard_pending_counts_cache_remembers_per_actor(): void
    {
        Cache::flush();

        $actor = User::factory()->create([
            'company_id' => null,
            'role' => 'admin',
        ]);

        $runs = 0;
        $counts = DashboardPendingCountsCache::remember($actor, function () use (&$runs) {
            $runs++;

            return [
                'leave' => 3,
                'overtime' => 2,
                'attendance_correction' => 1,
                'total' => 6,
            ];
        });

        $this->assertSame(1, $runs);
        $this->assertSame(6, $counts['total']);

        DashboardPendingCountsCache::remember($actor, function () use (&$runs) {
            $runs++;

            return [
                'leave' => 99,
                'overtime' => 0,
                'attendance_correction' => 0,
                'total' => 99,
            ];
        });

        $this->assertSame(1, $runs);
    }
}
