<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\BulkApproval\BulkApprovalCacheService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BulkApprovalCacheServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.bulk_approval_store', 'array');
        Cache::store('array')->flush();
    }

    public function test_preview_stores_ids_behind_user_scoped_token(): void
    {
        $actor = new User;
        $actor->id = 12;
        $service = app(BulkApprovalCacheService::class);

        $preview = $service->storePreview(
            'leave',
            $actor,
            ['status' => 'pending', 'date_from' => '2026-06-01'],
            [3, 1, 3, 2],
            4,
            ['not_current_approver' => 1],
        );

        $this->assertSame(3, $preview['eligible_count']);
        $this->assertSame(1, $preview['skipped_count']);
        $this->assertSame([3, 1, 2], $service->selection('leave', $actor, $preview['bulk_token'])['ids']);

        $otherActor = new User;
        $otherActor->id = 13;
        $this->assertNull($service->selection('leave', $otherActor, $preview['bulk_token']));
    }

    public function test_progress_is_accumulated_and_completed(): void
    {
        $service = app(BulkApprovalCacheService::class);
        $service->beginProgress('overtime', 'token-1', 100);
        $service->advanceProgress('overtime', 'token-1', [
            'processed' => 40,
            'approved' => 38,
            'skipped' => 2,
        ]);
        $service->finishProgress('overtime', 'token-1');

        $this->assertSame([
            'status' => 'completed',
            'total' => 100,
            'processed' => 40,
            'approved' => 38,
            'rejected' => 0,
            'skipped' => 2,
            'failed' => 0,
        ], array_intersect_key(
            $service->progress('overtime', 'token-1'),
            array_flip(['status', 'total', 'processed', 'approved', 'rejected', 'skipped', 'failed']),
        ));
    }

    public function test_attendance_uses_dedicated_selection_and_progress_keys(): void
    {
        $actor = new User;
        $actor->id = 24;
        $service = app(BulkApprovalCacheService::class);

        $preview = $service->storePreview(
            'attendance_correction',
            $actor,
            ['status' => 'pending'],
            [10, 11],
            2,
        );

        $this->assertTrue(Cache::store('array')->has(
            "attendance_bulk:24:{$preview['bulk_token']}",
        ));

        $service->beginProgress('attendance_correction', $preview['bulk_token'], 2);

        $this->assertTrue(Cache::store('array')->has(
            "attendance_bulk_progress:{$preview['bulk_token']}",
        ));
    }
}
