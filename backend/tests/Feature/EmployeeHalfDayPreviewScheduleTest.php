<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkingSchedule;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Half-day preview must resolve WorkingSchedule / assignment snapshots,
 * not only the legacy users.schedule JSON column.
 */
class EmployeeHalfDayPreviewScheduleTest extends TestCase
{
    public function test_halfday_preview_works_when_only_working_schedule_id_is_set(): void
    {
        if (! Schema::hasTable('working_schedules')) {
            $this->markTestSkipped('working_schedules table not available.');
        }

        $template = WorkingSchedule::create([
            'name' => 'Halfday Preview Shift',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 5,
            'rest_days' => ['sun'],
            ...(Schema::hasColumn('working_schedules', 'shift_type') ? ['shift_type' => 'fixed'] : []),
            ...(Schema::hasColumn('working_schedules', 'is_active') ? ['is_active' => true] : []),
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
            'working_schedule_id' => $template->id,
            'schedule' => null,
        ]);

        try {
            // Monday 2026-08-17 — working day under rest_days=['sun']
            $response = $this->actingAs($employee)->getJson('/api/leave/halfday-preview?date=2026-08-17&half_type=am');

            $response->assertOk();
            $response->assertJsonPath('date', '2026-08-17');
            $this->assertIsArray($response->json('windows'));
            $this->assertNotEmpty($response->json('suggested_time'));
        } finally {
            $employee->forceDelete();
            $template->delete();
        }
    }

    public function test_halfday_preview_falls_back_to_current_template_windows(): void
    {
        if (! Schema::hasTable('working_schedules')) {
            $this->markTestSkipped('working_schedules table not available.');
        }

        $template = WorkingSchedule::create([
            'name' => 'Current Template Only',
            'time_in' => '09:00',
            'time_out' => '18:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 5,
            'rest_days' => ['sun', 'sat'],
            ...(Schema::hasColumn('working_schedules', 'shift_type') ? ['shift_type' => 'fixed'] : []),
            ...(Schema::hasColumn('working_schedules', 'is_active') ? ['is_active' => true] : []),
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
            'working_schedule_id' => $template->id,
            'schedule' => null,
        ]);

        try {
            $response = $this->actingAs($employee)->getJson('/api/leave/halfday-preview?date=2026-08-18');
            $response->assertOk();
            $response->assertJsonPath('windows.scheduled_start', '09:00');
            $response->assertJsonPath('windows.scheduled_end', '18:00');
            $this->assertArrayHasKey('am', $response->json('windows'));
            $this->assertArrayHasKey('pm', $response->json('windows'));
        } finally {
            $employee->forceDelete();
            $template->delete();
        }
    }
}
