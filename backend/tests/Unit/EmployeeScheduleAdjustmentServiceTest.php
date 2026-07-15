<?php

namespace Tests\Unit;

use App\Models\EmployeeScheduleAssignment;
use App\Models\ScheduleAssignmentSnapshot;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\EmployeeScheduleAdjustmentService;
use App\Support\EmployeeScheduleResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeScheduleAdjustmentServiceTest extends TestCase
{
    public function test_effective_dated_adjustment_preserves_historical_schedule_snapshot(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Schedule assignment tables not available.');
        }

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
        ]);
        $oldSchedule = WorkingSchedule::create($this->schedulePayload('Old Day Shift', '08:00', '17:00', ['sun']));
        $newSchedule = WorkingSchedule::create($this->schedulePayload('New Mid Shift', '12:00', '21:00', ['sat']));

        try {
            /** @var EmployeeScheduleAdjustmentService $service */
            $service = app(EmployeeScheduleAdjustmentService::class);
            $service->apply([
                'employee_ids' => [(int) $employee->id],
                'schedule_template_id' => (int) $oldSchedule->id,
                'effective_start_date' => '2026-06-01',
                'adjustment_reason' => 'Initial assignment test',
                'replace_overlaps' => true,
            ]);

            $service->apply([
                'employee_ids' => [(int) $employee->id],
                'schedule_template_id' => (int) $newSchedule->id,
                'effective_start_date' => '2026-06-16',
                'adjustment_reason' => 'Shift change test',
                'replace_overlaps' => true,
            ]);

            $june10 = EmployeeScheduleResolver::resolveForDate($employee, '2026-06-10');
            $june16 = EmployeeScheduleResolver::resolveForDate($employee, '2026-06-16');

            $this->assertSame('08:00:00', $june10['wed']['in'] ?? null);
            $this->assertSame('17:00:00', $june10['wed']['out'] ?? null);
            $this->assertNull($june10['sun'] ?? null);
            $this->assertSame('12:00:00', $june16['tue']['in'] ?? null);
            $this->assertSame('21:00:00', $june16['tue']['out'] ?? null);
            $this->assertNull($june16['sat'] ?? null);

            $oldSchedule->forceFill(['time_in' => '10:00', 'time_out' => '19:00'])->save();
            $afterTemplateEdit = EmployeeScheduleResolver::resolveForDate($employee, '2026-06-10');

            $this->assertSame('08:00:00', $afterTemplateEdit['wed']['in'] ?? null);
            $this->assertSame('17:00:00', $afterTemplateEdit['wed']['out'] ?? null);

            $oldAssignment = EmployeeScheduleAssignment::query()
                ->where('employee_id', $employee->id)
                ->whereDate('effective_start_date', '2026-06-01')
                ->first();
            $this->assertSame('2026-06-15', $oldAssignment?->effective_end_date?->toDateString());
        } finally {
            $assignmentIds = EmployeeScheduleAssignment::query()
                ->where('employee_id', $employee->id)
                ->pluck('id');
            ScheduleAssignmentSnapshot::query()
                ->whereIn('employee_schedule_assignment_id', $assignmentIds)
                ->delete();
            EmployeeScheduleAssignment::query()
                ->whereIn('id', $assignmentIds)
                ->delete();
            $oldSchedule->delete();
            $newSchedule->delete();
            $employee->forceDelete();
        }
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
