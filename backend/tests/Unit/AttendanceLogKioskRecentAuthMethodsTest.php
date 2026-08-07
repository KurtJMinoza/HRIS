<?php

namespace Tests\Unit;

use App\Models\AttendanceLog;
use PHPUnit\Framework\TestCase;

class AttendanceLogKioskRecentAuthMethodsTest extends TestCase
{
    public function test_hr_approved_corrections_are_excluded_from_kiosk_recent_activity(): void
    {
        $excluded = AttendanceLog::nonKioskRecentAuthMethods();

        $this->assertContains(AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION, $excluded);
        $this->assertContains(AttendanceLog::AUTH_METHOD_ADMIN_MANUAL, $excluded);
        $this->assertNotContains(AttendanceLog::AUTH_METHOD_FACE, $excluded);
        $this->assertNotContains(AttendanceLog::AUTH_METHOD_QR, $excluded);
    }
}
