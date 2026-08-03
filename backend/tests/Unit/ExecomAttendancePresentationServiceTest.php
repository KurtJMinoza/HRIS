<?php

namespace Tests\Unit;

use App\Services\ExecomAttendancePresentationService;
use Tests\TestCase;

class ExecomAttendancePresentationServiceTest extends TestCase
{
    public function test_strip_stale_auto_present_without_punches_reverts_to_absent(): void
    {
        $service = app(ExecomAttendancePresentationService::class);

        $result = $service->stripStaleAutoPresent([
            'status' => 'present',
            'status_label' => 'Auto Present',
            'presence_label' => 'Auto Present',
            'presence_issue' => 'execom_auto_present',
            'payroll_impact_hours' => 8.0,
        ]);

        $this->assertSame('absent', $result['status']);
        $this->assertSame('Absent', $result['status_label']);
        $this->assertNull($result['presence_label']);
        $this->assertNull($result['presence_issue']);
        $this->assertSame(0.0, $result['payroll_impact_hours']);
    }

    public function test_strip_stale_auto_present_keeps_real_punches(): void
    {
        $service = app(ExecomAttendancePresentationService::class);

        $row = [
            'status' => 'present',
            'status_label' => 'Auto Present',
            'presence_label' => 'Auto Present',
            'presence_issue' => 'execom_auto_present',
            'time_in' => '08:00:00',
            'payroll_impact_hours' => 8.0,
        ];

        $this->assertSame($row, $service->stripStaleAutoPresent($row));
    }
}
