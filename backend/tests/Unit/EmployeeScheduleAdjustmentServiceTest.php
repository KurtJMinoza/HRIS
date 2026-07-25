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
            $this->cleanupEmployee($employee);
            $oldSchedule->delete();
            $newSchedule->delete();
        }
    }

    public function test_replace_overlaps_supersedes_same_start_open_ended_assignment(): void
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
        $first = WorkingSchedule::create($this->schedulePayload('First 7-4', '07:00', '16:00', ['sun']));
        $second = WorkingSchedule::create($this->schedulePayload('Second 8-5', '08:00', '17:00', ['sun']));

        try {
            $service = app(EmployeeScheduleAdjustmentService::class);
            $service->apply([
                'employee_ids' => [(int) $employee->id],
                'schedule_template_id' => (int) $first->id,
                'effective_start_date' => '2026-07-28',
                'adjustment_reason' => 'Initial until further notice',
                'replace_overlaps' => true,
            ]);

            $result = $service->apply([
                'employee_ids' => [(int) $employee->id],
                'schedule_template_id' => (int) $second->id,
                'effective_start_date' => '2026-07-28',
                'adjustment_reason' => 'Replace with 8-5 until further notice',
                'replace_overlaps' => true,
            ]);

            $this->assertSame(1, $result['assigned_count']);
            $this->assertSame([], $result['failed']);

            $active = EmployeeScheduleAssignment::query()
                ->active()
                ->where('employee_id', $employee->id)
                ->whereDate('effective_start_date', '2026-07-28')
                ->get();
            $this->assertCount(1, $active);
            $this->assertSame((int) $second->id, (int) $active->first()->schedule_template_id);
            $this->assertNull($active->first()->effective_end_date);

            $superseded = EmployeeScheduleAssignment::query()
                ->where('employee_id', $employee->id)
                ->where('assignment_status', EmployeeScheduleAssignment::STATUS_SUPERSEDED)
                ->whereDate('effective_start_date', '2026-07-28')
                ->count();
            $this->assertSame(1, $superseded);

            $resolved = EmployeeScheduleResolver::resolveForDate($employee, '2026-07-28');
            $this->assertSame('08:00:00', $resolved['tue']['in'] ?? null);
            $this->assertSame('17:00:00', $resolved['tue']['out'] ?? null);

            $third = WorkingSchedule::create($this->schedulePayload('Third 9-6', '09:00', '18:00', ['sun']));
            $again = $service->apply([
                'employee_ids' => [(int) $employee->id],
                'schedule_template_id' => (int) $third->id,
                'effective_start_date' => '2026-07-28',
                'adjustment_reason' => 'Replace again with 9-6',
                'replace_overlaps' => true,
            ]);
            $this->assertSame(1, $again['assigned_count']);
            $this->assertSame([], $again['failed']);
            $this->assertSame(
                1,
                EmployeeScheduleAssignment::query()
                    ->where('employee_id', $employee->id)
                    ->where('assignment_status', EmployeeScheduleAssignment::STATUS_SUPERSEDED)
                    ->whereDate('effective_start_date', '2026-07-28')
                    ->count()
            );
            $third->delete();
        } finally {
            $this->cleanupEmployee($employee);
            $first->delete();
            $second->delete();
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
