<?php

namespace Tests\Unit;

use App\Services\AttendanceMonthlySummaryService;
use PHPUnit\Framework\TestCase;

class AttendanceMonthlySummaryServiceTest extends TestCase
{
    public function test_it_uses_scheduled_workdays_and_paid_hours_for_dashboard_metrics(): void
    {
        $days = [];
        for ($day = 1; $day <= 6; $day++) {
            $isFuture = $day === 6;
            $days[] = [
                'date' => '2026-07-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT),
                'status' => $isFuture ? 'upcoming' : 'present',
                'status_label' => $isFuture ? 'Upcoming' : 'Present',
                'is_present' => ! $isFuture,
                'is_rest_day' => false,
                'scheduled_regular_hours' => 8,
                'worked_hours' => $isFuture ? null : 8,
                'payroll_impact_hours' => $isFuture ? null : 8,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'schedule_in' => '08:00',
                'schedule_out' => '17:00',
            ];
        }

        $summary = (new AttendanceMonthlySummaryService)->summarize($days);

        $this->assertSame(6, $summary['scheduled_workdays']);
        $this->assertSame(5, $summary['present_days']);
        $this->assertSame(83.33, $summary['present_percentage']);
        $this->assertSame(0, $summary['absent_days']);
        $this->assertSame(48.0, $summary['scheduled_paid_hours']);
        $this->assertSame(40.0, $summary['payroll_impact_hours']);
        $this->assertSame(83.33, $summary['efficiency_percentage']);
    }

    public function test_it_excludes_unworked_rest_and_non_payable_holiday_days(): void
    {
        $summary = (new AttendanceMonthlySummaryService)->summarize([
            [
                'date' => '2026-07-01',
                'status' => 'rest',
                'is_rest_day' => true,
                'scheduled_regular_hours' => null,
                'worked_hours' => null,
                'payroll_impact_hours' => null,
            ],
            [
                'date' => '2026-07-02',
                'status' => 'holiday',
                'is_rest_day' => false,
                'scheduled_regular_hours' => 8,
                'worked_hours' => null,
                'payroll_impact_hours' => 0,
            ],
        ]);

        $this->assertSame(0, $summary['scheduled_workdays']);
        $this->assertSame(0.0, $summary['scheduled_paid_hours']);
        $this->assertSame(0.0, $summary['efficiency_percentage']);
    }
}
