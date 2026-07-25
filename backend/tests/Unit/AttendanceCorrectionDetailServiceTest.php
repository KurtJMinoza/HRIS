<?php

namespace Tests\Unit;

use App\Models\EmployeeScheduleAssignment;
use App\Models\ScheduleAssignmentSnapshot;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\AttendanceCorrectionDetailService;
use App\Services\EmployeeScheduleAdjustmentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceCorrectionDetailServiceTest extends TestCase
{
    public function test_detail_uses_schedule_effective_on_filing_date_not_today(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Schedule assignment tables not available.');
        }

        // Before the Jul 28 adjustment takes effect — proves we do not use resolve(today).
        Carbon::setTestNow(Carbon::parse('2026-07-25 10:00:00', 'Asia/Manila'));

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
        ]);
        $oldSchedule = WorkingSchedule::create($this->schedulePayload('Old 7-4', '07:00', '16:00', ['sun']));
        $newSchedule = WorkingSchedule::create($this->schedulePayload('New 8-5', '08:00', '17:00', ['sun']));

        try {
            $adjuster = app(EmployeeScheduleAdjustmentService::class);
            $adjuster->apply([
                'employee_ids' => [(int) $employee->id],
                'schedule_template_id' => (int) $oldSchedule->id,
                'effective_start_date' => '2026-07-01',
                'adjustment_reason' => 'Baseline 7-4',
                'replace_overlaps' => true,
            ]);
            $adjuster->apply([
                'employee_ids' => [(int) $employee->id],
                'schedule_template_id' => (int) $newSchedule->id,
                'effective_start_date' => '2026-07-28',
                'adjustment_reason' => 'Upcoming 8-5',
                'replace_overlaps' => true,
            ]);

            $detail = app(AttendanceCorrectionDetailService::class)->resolve(
                $employee,
                '2026-07-28',
                'both',
                true,
            );

            $this->assertSame('08:00', $detail['schedule_start']);
            $this->assertSame('17:00', $detail['schedule_end']);
        } finally {
            Carbon::setTestNow();
            $this->cleanupEmployee($employee);
            $oldSchedule->delete();
            $newSchedule->delete();
        }
    }

    private function cleanupEmployee(User $employee): void
    {
        $assignmentIds = EmployeeScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->pluck('id');
        ScheduleAssignmentSnapshot::query()
            ->whereIn('employee_schedule_assignment_id', $assignmentIds)
            ->delete();
        EmployeeScheduleAssignment::query()
            ->whereIn('id', $assignmentIds)
            ->delete();
        $employee->forceDelete();
    }

    private function schedulePayload(string $name, string $in, string $out, array $restDays): array
    {
        $payload = [
            'name' => $name,
            'time_in' => $in,
            'time_out' => $out,
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 5,
            'rest_days' => $restDays,
        ];

        if (Schema::hasColumn('working_schedules', 'shift_type')) {
            $payload['shift_type'] = 'fixed';
        }
        if (Schema::hasColumn('working_schedules', 'is_active')) {
            $payload['is_active'] = true;
        }

        return $payload;
    }

    private function tablesExist(): bool
    {
        try {
            DB::select('SELECT 1 FROM employee_schedule_assignments LIMIT 1');
            DB::select('SELECT 1 FROM schedule_assignment_snapshots LIMIT 1');
            DB::select('SELECT 1 FROM working_schedules LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
