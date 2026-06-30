<?php

namespace Tests\Unit;

use App\Models\AttendanceCorrection;
use App\Services\AttendanceCorrectionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceCorrectionStatusCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregate_status_counts_clears_prior_select_columns(): void
    {
        $query = AttendanceCorrection::query()
            ->select([
                'id',
                'user_id',
                'date',
                'time_in',
                'time_out',
                'remarks',
                'issue_kind',
                'approved',
                'status',
                'filed_at',
            ])
            ->where('user_id', 1)
            ->where(function ($sub): void {
                $sub->whereNotNull('filed_at')->orWhereNotNull('reason_code');
            });

        $counts = app(AttendanceCorrectionStatusService::class)->aggregateStatusCounts($query);

        $this->assertSame(
            ['total', 'pending', 'approved', 'rejected', 'cancelled'],
            array_keys($counts),
        );
        $this->assertSame(0, $counts['total']);
    }
}
