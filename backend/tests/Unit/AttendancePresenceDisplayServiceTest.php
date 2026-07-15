<?php

namespace Tests\Unit;

use App\Services\AttendancePresenceDisplayService;
use Carbon\Carbon;
use Tests\TestCase;

class AttendancePresenceDisplayServiceTest extends TestCase
{
    public function test_incomplete_pair_remains_auditable_when_displayed_as_present(): void
    {
        $service = app(AttendancePresenceDisplayService::class);
        $now = Carbon::parse('2026-07-13 18:00:00', 'Asia/Manila');

        $result = $service->qualify(
            '2026-07-13',
            '2026-07-13',
            $now,
            ['in' => '08:00:00', 'out' => '17:00:00'],
            'incomplete',
            $now->copy()->setTime(8, 1),
            null,
            null,
            false,
        );

        $this->assertSame('present', $result['status']);
        $this->assertSame('incomplete_pair', $result['presence_issue']);
        $this->assertSame('Present (Incomplete Records)', $result['presence_label']);
    }
}
