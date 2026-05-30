<?php

namespace Tests\Unit;

use App\Services\AttendanceRollupService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceRollupServiceTest extends TestCase
{
    private AttendanceRollupService $rollup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rollup = new AttendanceRollupService;
    }

    #[Test]
    public function employee_summary_matches_display_labels_for_sample_period(): void
    {
        $days = [
            ['status' => 'rest', 'is_rest_day' => true, 'date' => '2026-05-10'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-05-09'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-05-08'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-05-07'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-05-06'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-05-05'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-05-04'],
            ['status' => 'rest', 'is_rest_day' => true, 'date' => '2026-05-03'],
            ['status' => 'absent', 'date' => '2026-05-02'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-05-01'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-04-30'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-04-29'],
            ['status' => 'present', 'late_label' => 'Present', 'date' => '2026-04-28'],
            [
                'status' => 'present',
                'presence_label' => 'Present (Approved)',
                'date' => '2026-04-27',
            ],
            ['status' => 'rest', 'is_rest_day' => true, 'date' => '2026-04-26'],
        ];

        $counts = $this->rollup->summarizeEmployeeDays($days);

        $this->assertSame(11, $counts['present_count']);
        $this->assertSame(1, $counts['absent_count']);
        $this->assertSame(3, $counts['rest_day_count']);
        $this->assertSame(0, $counts['late_count']);
        $this->assertSame(0, $counts['leave_count']);
    }

    #[Test]
    public function grace_period_late_label_present_counts_as_present_not_late(): void
    {
        $days = [
            ['status' => 'late', 'late_label' => 'Present', 'date' => '2026-05-01'],
        ];

        $counts = $this->rollup->summarizeEmployeeDays($days);

        $this->assertSame(1, $counts['present_count']);
        $this->assertSame(0, $counts['late_count']);
    }

    #[Test]
    public function scheduled_rest_day_is_detected_from_empty_shift_in(): void
    {
        $schedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00'],
        ];

        $this->assertTrue($this->rollup->isScheduledRestDay($schedule, null));
        $this->assertFalse($this->rollup->isScheduledRestDay($schedule, ['in' => '08:00', 'out' => '17:00']));
    }
}
