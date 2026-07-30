<?php

namespace App\Services;

use App\Events\ScheduleUpdated;
use App\Models\EmployeeScheduleAssignment;
use App\Models\ScheduleAssignmentSnapshot;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EmployeeScheduleAdjustmentService
{
    public function __construct(
        private readonly PayrollFreezeService $payrollFreeze,
    ) {}

    /**
     * @param  array{
     *   employee_ids?: array<int>,
     *   scope_type?: string,
     *   scope_ids?: array<int>,
     *   schedule_template_id?: int|null,
     *   custom_schedule?: array<string,mixed>|null,
     *   effective_start_date: string,
     *   effective_end_date?: string|null,
     *   source_scope_type?: string|null,
     *   source_scope_id?: int|null,
     *   is_adjustment?: bool,
     *   adjustment_reason?: string|null,
     *   created_by?: int|null,
     *   replace_overlaps?: bool,
     *   status?: string
     * } $payload
     * @return array{assigned_count:int, assigned_ids:list<int>, failed:list<array{employee_id:int, reason:string}>}
     */
    public function apply(array $payload): array
    {
        $employeeIds = $this->resolveEmployeeIds($payload);
        if ($employeeIds === []) {
            throw ValidationException::withMessages(['employee_ids' => 'No active employees match the selected schedule adjustment scope.']);
        }

        $start = Carbon::parse($payload['effective_start_date'])->startOfDay();
        $end = ! empty($payload['effective_end_date']) ? Carbon::parse($payload['effective_end_date'])->startOfDay() : null;
        if ($end !== null && $end->lt($start)) {
            throw ValidationException::withMessages(['effective_end_date' => 'Effective end date must be on or after the start date.']);
        }

        $template = null;
        if (! empty($payload['schedule_template_id'])) {
            $template = WorkingSchedule::findOrFail((int) $payload['schedule_template_id']);
        }

        if (! $template && empty($payload['custom_schedule'])) {
            throw ValidationException::withMessages(['schedule_template_id' => 'Select a schedule template or provide a custom schedule.']);
        }

        $assignedIds = [];
        $failed = [];
        $affectedIds = [];
        $replaceOverlaps = (bool) ($payload['replace_overlaps'] ?? true);
        $status = $payload['status'] ?? EmployeeScheduleAssignment::STATUS_ACTIVE;
        $isActiveAssignment = $status === EmployeeScheduleAssignment::STATUS_ACTIVE;

        DB::transaction(function () use (
            $employeeIds,
            $start,
            $end,
            $template,
            $payload,
            $replaceOverlaps,
            $status,
            $isActiveAssignment,
            &$assignedIds,
            &$failed,
            &$affectedIds
        ): void {
            $employees = User::query()
                ->visibleEmployees()
                ->active()
                ->whereIn('id', $employeeIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($employeeIds as $employeeId) {
                $employee = $employees->get($employeeId);
                if (! $employee) {
                    $failed[] = ['employee_id' => $employeeId, 'reason' => 'Employee is not active or visible in the roster.'];
                    continue;
                }

                $freezeTo = $end ?? $start;
                $frozen = $this->payrollFreeze->isWindowFrozenForEmployee((int) $employee->id, $start, $freezeTo);
                if ($frozen['frozen']) {
                    $failed[] = [
                        'employee_id' => (int) $employee->id,
                        'reason' => PayrollFreezeService::LOCK_MESSAGE,
                    ];
                    continue;
                }

                $overlaps = EmployeeScheduleAssignment::query()
                    ->active()
                    ->where('employee_id', (int) $employee->id)
                    ->whereDate('effective_start_date', '<=', ($end ?? Carbon::create(9999, 12, 31))->toDateString())
                    ->where(function (Builder $q) use ($start): void {
                        $q->whereNull('effective_end_date')
                            ->orWhereDate('effective_end_date', '>=', $start->toDateString());
                    })
                    ->orderBy('effective_start_date')
                    ->get();

                $blocking = $isActiveAssignment ? $this->blockingOverlaps($overlaps, $replaceOverlaps) : [];
                if ($blocking !== []) {
                    $failed[] = [
                        'employee_id' => (int) $employee->id,
                        'reason' => 'Schedule assignment overlaps an existing historical period. Choose replace overlapping period or adjust the dates.',
                    ];
                    continue;
                }

                if ($isActiveAssignment && $replaceOverlaps) {
                    $this->shortenOpenAssignments($overlaps, $start);
                    $this->supersedeForwardAssignments($overlaps, $start, $end);
                }

                if ($isActiveAssignment) {
                    $this->ensureHistoricalBaseline($employee, $start, $payload);
                }

                $assignment = EmployeeScheduleAssignment::create([
                    'employee_id' => (int) $employee->id,
                    'schedule_template_id' => $template?->id,
                    'effective_start_date' => $start->toDateString(),
                    'effective_end_date' => $end?->toDateString(),
                    'assignment_type' => $template ? 'template' : 'custom',
                    'source_scope_type' => $payload['source_scope_type'] ?? $payload['scope_type'] ?? 'employee',
                    'source_scope_id' => $payload['source_scope_id'] ?? null,
                    'assignment_status' => $status,
                    'is_adjustment' => (bool) ($payload['is_adjustment'] ?? true),
                    'adjustment_reason' => $payload['adjustment_reason'] ?? null,
                    'created_by' => $payload['created_by'] ?? null,
                ]);

                $snapshot = $this->createSnapshot($assignment, $template, $payload['custom_schedule'] ?? null);
                $assignment->forceFill(['assignment_snapshot_id' => $snapshot->id])->save();

                if ($isActiveAssignment) {
                    $this->syncLegacyCurrentSchedule($employee, $assignment, $template, $start);
                    $this->forgetEmployeeScheduleCaches((int) $employee->id);
                }

                $assignedIds[] = (int) $employee->id;
                if ($isActiveAssignment) {
                    $affectedIds[] = (int) $employee->id;
                }
            }
        });

        if ($affectedIds !== []) {
            ScheduleUpdated::dispatch($template, array_values(array_unique($affectedIds)), 'assigned');
        }

        return [
            'assigned_count' => count($assignedIds),
            'assigned_ids' => $assignedIds,
            'failed' => $failed,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<int>
     */
    public function resolveEmployeeIds(array $payload): array
    {
        $explicit = array_values(array_unique(array_map('intval', $payload['employee_ids'] ?? [])));
        if ($explicit !== []) {
            return $explicit;
        }

        $scopeType = (string) ($payload['scope_type'] ?? 'employee');
        $scopeIds = array_values(array_unique(array_filter(array_map('intval', $payload['scope_ids'] ?? []))));
        if ($scopeIds === []) {
            return [];
        }

        $query = User::query()->visibleEmployees()->active();

        match ($scopeType) {
            'company', 'companies' => $query->where(function (Builder $q) use ($scopeIds): void {
                $q->whereIn('company_id', $scopeIds)
                    ->orWhereHas('organizationAssignments', fn (Builder $assignment) => $assignment
                        ->active()
                        ->whereIn('company_id', $scopeIds));
            }),
            'area', 'areas' => $query->where(function (Builder $q) use ($scopeIds): void {
                $q->whereHas('branch', fn (Builder $branch) => $branch->whereIn('area_id', $scopeIds))
                    ->orWhereHas('organizationAssignments', fn (Builder $assignment) => $assignment
                        ->active()
                        ->whereIn('branch_id', fn ($branches) => $branches
                            ->select('id')
                            ->from('branches')
                            ->whereIn('area_id', $scopeIds)));
            }),
            'branch', 'branches' => $query->where(function (Builder $q) use ($scopeIds): void {
                $q->whereIn('branch_id', $scopeIds)
                    ->orWhereHas('organizationAssignments', fn (Builder $assignment) => $assignment
                        ->active()
                        ->whereIn('branch_id', $scopeIds));
            }),
            'division', 'divisions' => $query->where(function (Builder $q) use ($scopeIds): void {
                $q->whereIn('division_id', $scopeIds)
                    ->orWhereHas('organizationAssignments', fn (Builder $assignment) => $assignment
                        ->active()
                        ->whereIn('division_id', $scopeIds));
            }),
            'department', 'departments' => $query->where(function (Builder $q) use ($scopeIds): void {
                $q->whereIn('department_id', $scopeIds)
                    ->orWhereHas('organizationAssignments', fn (Builder $assignment) => $assignment
                        ->active()
                        ->whereIn('department_id', $scopeIds));
            }),
            'section', 'sections', 'section_unit', 'section_units' => $query->where(function (Builder $q) use ($scopeIds): void {
                $q->whereIn('section_unit_id', $scopeIds)
                    ->orWhereHas('organizationAssignments', fn (Builder $assignment) => $assignment
                        ->active()
                        ->whereIn('section_unit_id', $scopeIds));
            }),
            default => $query->whereRaw('1 = 0'),
        };

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function createSnapshot(
        EmployeeScheduleAssignment $assignment,
        ?WorkingSchedule $template,
        ?array $customSchedule
    ): ScheduleAssignmentSnapshot {
        $source = $template ?? new WorkingSchedule($customSchedule ?? []);
        if ($template && Schema::hasTable('working_schedule_days')) {
            $template->loadMissing('days');
        }
        $schedulePayload = $template
            ? EmployeeScheduleResolver::buildFromWorkingSchedule($template)
            : EmployeeScheduleResolver::buildFromWorkingSchedule($source);
        $restDays = is_array($source->rest_days) ? $source->rest_days : [];
        $workweekDays = array_values(array_diff(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $restDays));

        return ScheduleAssignmentSnapshot::create([
            'employee_schedule_assignment_id' => $assignment->id,
            'schedule_name' => (string) ($source->name ?: 'Custom schedule adjustment'),
            'schedule_type' => (string) ($source->shift_type ?: 'fixed'),
            'start_time' => $source->time_in,
            'end_time' => $source->time_out,
            'crosses_midnight' => (bool) ($source->crosses_midnight ?? false),
            'scheduled_minutes' => $this->scheduledSpanMinutes($source),
            'paid_minutes' => $source->effective_paid_minutes,
            'grace_period_minutes' => (int) ($source->grace_period_minutes ?? 0),
            'late_deduction_policy' => [
                'late_allowance_minutes' => $source->late_allowance_minutes,
                'early_timeout_minutes' => $source->early_timeout_minutes,
            ],
            'half_day_policy' => [
                'half_day_threshold_minutes' => $source->effective_half_day_threshold,
            ],
            'workweek_days' => $workweekDays,
            'rest_days' => $restDays,
            'break_rules' => [
                'break_start' => $source->break_start,
                'break_end' => $source->break_end,
                'breaks' => $source->getAllBreaks(),
                'work_blocks' => $source->getWorkBlocks(),
            ],
            'overtime_rules' => [
                'overtime_buffer_minutes' => $source->overtime_buffer_minutes ?? 15,
            ],
            'night_differential_rules' => [],
            'schedule_payload' => $schedulePayload,
        ]);
    }

    /**
     * @param  Collection<int, EmployeeScheduleAssignment>  $overlaps
     * @return list<int>
     */
    private function blockingOverlaps(Collection $overlaps, bool $replaceOverlaps): array
    {
        // replace_overlaps: past rows are shortened, same-start/future overlaps are superseded.
        if ($replaceOverlaps) {
            return [];
        }

        return $overlaps->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param Collection<int, EmployeeScheduleAssignment> $overlaps */
    private function shortenOpenAssignments(Collection $overlaps, Carbon $start): void
    {
        $closeOn = $start->copy()->subDay()->toDateString();
        foreach ($overlaps as $assignment) {
            if ($assignment->effective_start_date->lt($start)) {
                $assignment->forceFill(['effective_end_date' => $closeOn])->save();
            }
        }
    }

    /**
     * When replacing, retire active assignments that start on/after the new window
     * (e.g. an earlier "until further notice" row starting the same day).
     *
     * Unique key (employee_id, effective_start_date, assignment_status) allows only one
     * superseded row per start date — purge older superseded twins before updating.
     *
     * @param  Collection<int, EmployeeScheduleAssignment>  $overlaps
     */
    private function supersedeForwardAssignments(Collection $overlaps, Carbon $start, ?Carbon $end): void
    {
        foreach ($overlaps as $assignment) {
            if ($assignment->effective_start_date->lt($start)) {
                continue;
            }
            if ($end !== null && $assignment->effective_start_date->gt($end)) {
                continue;
            }

            $this->purgeSupersededForStart(
                (int) $assignment->employee_id,
                $assignment->effective_start_date->toDateString(),
                (int) $assignment->id,
            );

            $assignment->forceFill([
                'assignment_status' => EmployeeScheduleAssignment::STATUS_SUPERSEDED,
            ])->save();
        }
    }

    private function purgeSupersededForStart(int $employeeId, string $startDate, int $exceptId): void
    {
        $oldIds = EmployeeScheduleAssignment::query()
            ->where('employee_id', $employeeId)
            ->whereDate('effective_start_date', $startDate)
            ->where('assignment_status', EmployeeScheduleAssignment::STATUS_SUPERSEDED)
            ->where('id', '!=', $exceptId)
            ->pluck('id');

        if ($oldIds->isEmpty()) {
            return;
        }

        ScheduleAssignmentSnapshot::query()
            ->whereIn('employee_schedule_assignment_id', $oldIds)
            ->delete();
        EmployeeScheduleAssignment::query()
            ->whereIn('id', $oldIds)
            ->delete();
    }

    private function ensureHistoricalBaseline(User $employee, Carbon $newStart, array $payload): void
    {
        $closeOn = $newStart->copy()->subDay()->startOfDay();
        if ($closeOn->lt(Carbon::create(1970, 1, 1)->startOfDay())) {
            return;
        }

        $hasHistoricalSchedule = EmployeeScheduleAssignment::query()
            ->active()
            ->where('employee_id', (int) $employee->id)
            ->coveringDate($closeOn->toDateString())
            ->exists();

        if ($hasHistoricalSchedule) {
            return;
        }

        $employee->loadMissing('workingSchedule');
        $legacyTemplate = $employee->workingSchedule;
        if (! $legacyTemplate instanceof WorkingSchedule) {
            return;
        }

        $baselineStart = $employee->hire_date instanceof Carbon
            ? $employee->hire_date->copy()->startOfDay()
            : ($employee->created_at instanceof Carbon ? $employee->created_at->copy()->startOfDay() : Carbon::create(1970, 1, 1)->startOfDay());

        if ($baselineStart->gt($closeOn)) {
            $baselineStart = $closeOn->copy();
        }

        $baseline = EmployeeScheduleAssignment::create([
            'employee_id' => (int) $employee->id,
            'schedule_template_id' => (int) $legacyTemplate->id,
            'effective_start_date' => $baselineStart->toDateString(),
            'effective_end_date' => $closeOn->toDateString(),
            'assignment_type' => 'template',
            'source_scope_type' => 'employee',
            'source_scope_id' => (int) $employee->id,
            'assignment_status' => EmployeeScheduleAssignment::STATUS_ACTIVE,
            'is_adjustment' => false,
            'adjustment_reason' => 'Historical schedule before schedule adjustment effective '.$newStart->toDateString(),
            'created_by' => $payload['created_by'] ?? null,
        ]);

        $snapshot = $this->createSnapshot($baseline, $legacyTemplate, null);
        $baseline->forceFill(['assignment_snapshot_id' => $snapshot->id])->save();
    }

    private function syncLegacyCurrentSchedule(User $employee, EmployeeScheduleAssignment $assignment, ?WorkingSchedule $template, Carbon $start): void
    {
        $today = Carbon::now(config('attendance.timezone', config('app.timezone', 'Asia/Manila')))->startOfDay();
        if ($assignment->effective_end_date instanceof Carbon && $assignment->effective_end_date->lt($today)) {
            return;
        }

        if ($start->gt($today)) {
            $employee->forceFill([
                'pending_working_schedule_id' => $template?->id,
                'pending_schedule_effective_from' => $start->toDateString(),
            ])->save();
            return;
        }

        $employee->forceFill([
            'schedule' => null,
            'working_schedule_id' => $template?->id,
            'pending_working_schedule_id' => null,
            'pending_schedule_effective_from' => null,
        ])->save();
    }

    private function scheduledSpanMinutes(WorkingSchedule $schedule): ?int
    {
        if (! $schedule->time_in || ! $schedule->time_out) {
            return null;
        }

        $span = WorkingSchedule::timeToMinutes($schedule->time_out) - WorkingSchedule::timeToMinutes($schedule->time_in);
        if ($span <= 0) {
            $span += 1440;
        }

        return max(0, $span);
    }

    private function forgetEmployeeScheduleCaches(int $employeeId): void
    {
        Cache::forget("employee:schedule:{$employeeId}");
        Cache::forget("employee:calendar:{$employeeId}");
        Cache::forget("attendance:schedule:{$employeeId}");
        Cache::forget("attendance:calendar:{$employeeId}");
        Cache::forget("attendance:summary:{$employeeId}");
        Cache::forget("payroll:preview:{$employeeId}");
    }
}
