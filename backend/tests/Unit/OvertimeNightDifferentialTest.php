<?php

namespace Tests\Unit;

use App\Models\Overtime;
use App\Services\OvertimePayrollService;
use Carbon\Carbon;
use Tests\TestCase;

class OvertimeNightDifferentialTest extends TestCase
{
    private function makeApprovedOvertime(string $dateKey, string $start, string $end, float $hours): Overtime
    {
        $ot = new Overtime([
            'date' => $dateKey,
            'schedule_end' => $start,
            'expected_end_time' => $end,
            'computed_minutes' => (int) round($hours * 60),
            'computed_hours' => $hours,
            'ph_ot_rule' => 'ORD',
            'status' => Overtime::STATUS_APPROVED,
        ]);
        $ot->id = 1;

        return $ot;
    }

    public function test_approved_ot_5pm_to_11pm_has_one_nd_hour(): void
    {
        $service = app(OvertimePayrollService::class);
        $tz = 'Asia/Manila';
        $dateKey = '2026-05-12';
        $records = [$this->makeApprovedOvertime($dateKey, '17:00:00', '23:00:00', 6.0)];

        $comp = $service->computeCompensationFromRecords($records, 100.0, null, 'ORD', 0, $dateKey, null, $tz);

        $this->assertSame(6.0, $comp['approved_hours']);
        $this->assertSame(1.0, $comp['nd_hours']);
        $this->assertGreaterThan(0.0, (float) $comp['nd_pay']);
        $this->assertSame(12.5, (float) $comp['nd_pay']); // 1h × 100 × 1.25 × 0.10
        $this->assertCount(1, $comp['nd_items']);
        $this->assertSame('night_differential', $comp['nd_items'][0]['category']);
    }

    public function test_approved_ot_5pm_to_8pm_has_zero_nd_hours(): void
    {
        $service = app(OvertimePayrollService::class);
        $tz = 'Asia/Manila';
        $dateKey = '2026-05-12';
        $records = [$this->makeApprovedOvertime($dateKey, '17:00:00', '20:00:00', 3.0)];

        $comp = $service->computeCompensationFromRecords($records, 100.0, null, 'ORD', 0, $dateKey, null, $tz);

        $this->assertSame(0.0, $comp['nd_hours']);
        $this->assertSame(0.0, (float) $comp['nd_pay']);
        $this->assertSame([], $comp['nd_items']);
    }

    public function test_approved_ot_10pm_to_2am_has_four_nd_hours(): void
    {
        $service = app(OvertimePayrollService::class);
        $tz = 'Asia/Manila';
        $dateKey = '2026-05-12';
        $records = [$this->makeApprovedOvertime($dateKey, '22:00:00', '02:00:00', 4.0)];

        $comp = $service->computeCompensationFromRecords($records, 100.0, null, 'ORD', 0, $dateKey, null, $tz);

        $this->assertSame(4.0, $comp['nd_hours']);
        $this->assertSame(50.0, (float) $comp['nd_pay']); // 4h × 100 × 1.25 × 0.10
    }

    public function test_night_minutes_in_interval_matches_window(): void
    {
        $service = app(OvertimePayrollService::class);
        $tz = 'Asia/Manila';
        $start = Carbon::parse('2026-05-12 17:00', $tz);
        $end = Carbon::parse('2026-05-12 23:00', $tz);

        $this->assertSame(60, $service->nightMinutesInInterval($start, $end, $tz));
    }
}
