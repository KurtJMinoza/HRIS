<?php

namespace App\Services;

use App\Models\BranchGeofence;
use App\Models\EmployeeGeofenceAssignment;
use App\Models\EmployeeGeofenceAssignmentAudit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeGeofenceAssignmentService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyBulkAssignment(array $payload, User $actor): array
    {
        $employeeIds = collect($payload['employee_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $action = (string) ($payload['assignment_action'] ?? 'add');
        $validationMode = (string) ($payload['validation_mode'] ?? 'any_assigned_geofence');
        $effectiveStart = Carbon::parse($payload['effective_start_date'] ?? now()->toDateString())->startOfDay();
        $effectiveEnd = isset($payload['effective_end_date']) && $payload['effective_end_date'] !== ''
            ? Carbon::parse($payload['effective_end_date'])->endOfDay()
            : null;
        $reason = $payload['reason'] ?? null;
        $approvedBy = isset($payload['approved_by']) ? (int) $payload['approved_by'] : null;
        $customizePerEmployee = (bool) ($payload['customize_per_employee'] ?? false);
        $assignments = collect($payload['assignments'] ?? []);
        $sharedGeofenceIds = collect($payload['geofence_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $primaryGeofenceId = isset($payload['primary_geofence_id']) ? (int) $payload['primary_geofence_id'] : null;

        if ($employeeIds->isEmpty()) {
            throw ValidationException::withMessages(['employee_ids' => ['Select at least one employee.']]);
        }

        $created = [];

        DB::transaction(function () use (
            $employeeIds,
            $action,
            $validationMode,
            $effectiveStart,
            $effectiveEnd,
            $reason,
            $approvedBy,
            $customizePerEmployee,
            $assignments,
            $sharedGeofenceIds,
            $primaryGeofenceId,
            $actor,
            &$created,
        ): void {
            foreach ($employeeIds as $employeeId) {
                $previous = $this->currentAssignmentState((int) $employeeId);

                if (in_array($action, ['replace', 'replace_current_assignments'], true)) {
                    $this->endActiveAssignments((int) $employeeId, $actor, 'Assignment replaced.', $approvedBy);
                }

                $employeeRows = $customizePerEmployee
                    ? collect($assignments)->firstWhere('employee_id', $employeeId)
                    : null;

                $geofenceRows = $employeeRows['geofences'] ?? null;
                if ($geofenceRows === null && $sharedGeofenceIds->isNotEmpty()) {
                    $geofenceRows = $sharedGeofenceIds->map(fn (int $geofenceId): array => [
                        'geofence_id' => $geofenceId,
                        'role' => $geofenceId === $primaryGeofenceId ? 'primary' : 'additional',
                        'assignment_type' => 'permanent',
                    ])->all();
                }

                if ($action === 'remove_selected_geofences') {
                    $removeIds = collect($geofenceRows ?? [])->pluck('geofence_id')->map(fn ($id): int => (int) $id)->all();
                    $this->removeGeofences((int) $employeeId, $removeIds, $actor, $reason, $approvedBy);
                } elseif ($action === 'change_primary_geofence' && $primaryGeofenceId) {
                    $this->changePrimary((int) $employeeId, $primaryGeofenceId, $actor, $reason, $approvedBy);
                } elseif (is_array($geofenceRows)) {
                    foreach ($geofenceRows as $row) {
                        $geofenceId = (int) ($row['geofence_id'] ?? 0);
                        if ($geofenceId <= 0) {
                            continue;
                        }
                        $role = strtolower((string) ($row['role'] ?? 'additional'));
                        $assignmentType = strtolower((string) ($row['assignment_type'] ?? ($role === 'temporary' ? 'temporary' : 'permanent')));
                        $created[] = $this->createAssignment([
                            'employee_id' => (int) $employeeId,
                            'geofence_id' => $geofenceId,
                            'assignment_type' => in_array($assignmentType, ['permanent', 'temporary'], true) ? $assignmentType : 'permanent',
                            'validation_mode' => $validationMode,
                            'is_primary' => $role === 'primary',
                            'effective_start_date' => $effectiveStart->toDateString(),
                            'effective_end_date' => $effectiveEnd?->toDateString(),
                            'reason' => $reason,
                            'approved_by' => $approvedBy,
                            'created_by' => (int) $actor->id,
                        ]);
                    }
                }

                $this->audit(
                    (int) $employeeId,
                    'employee_geofence_assigned',
                    $previous,
                    $this->currentAssignmentState((int) $employeeId),
                    $reason,
                    (int) $actor->id,
                    $approvedBy,
                );
            }
        });

        return ['created_count' => count($created), 'assignments' => $created];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createExemption(array $payload, User $actor): EmployeeGeofenceAssignment
    {
        $employeeId = (int) ($payload['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            throw ValidationException::withMessages(['employee_id' => ['Employee is required.']]);
        }

        $previous = $this->currentAssignmentState($employeeId);
        $assignment = $this->createAssignment([
            'employee_id' => $employeeId,
            'geofence_id' => null,
            'assignment_type' => 'exemption',
            'validation_mode' => 'no_geofence_required',
            'is_primary' => false,
            'effective_start_date' => Carbon::parse($payload['effective_start_date'] ?? now())->toDateString(),
            'effective_end_date' => isset($payload['effective_end_date']) && $payload['effective_end_date'] !== ''
                ? Carbon::parse($payload['effective_end_date'])->toDateString()
                : null,
            'clock_in_applies' => $this->actionApplies($payload, 'clock_in'),
            'clock_out_applies' => $this->actionApplies($payload, 'clock_out'),
            'reason' => $payload['reason'] ?? null,
            'approved_by' => isset($payload['approved_by']) ? (int) $payload['approved_by'] : null,
            'created_by' => (int) $actor->id,
        ]);

        $this->audit(
            $employeeId,
            'exemption_created',
            $previous,
            $this->currentAssignmentState($employeeId),
            $payload['reason'] ?? null,
            (int) $actor->id,
            isset($payload['approved_by']) ? (int) $payload['approved_by'] : null,
        );

        return $assignment;
    }

    /**
     * @return array<string, mixed>
     */
    public function setLocationOnlyMode(int $employeeId, bool $enabled, User $actor, ?string $reason = null): array
    {
        if ($employeeId <= 0) {
            throw ValidationException::withMessages(['employee_id' => ['Employee is required.']]);
        }

        $previous = $this->currentAssignmentState($employeeId);

        if ($enabled) {
            $existing = EmployeeGeofenceAssignment::query()
                ->where('employee_id', $employeeId)
                ->where('validation_mode', 'location_only')
                ->whereNotIn('status', ['removed', 'replaced'])
                ->first();

            if ($existing) {
                $existing->fill([
                    'status' => 'active',
                    'effective_start_date' => now()->toDateString(),
                    'effective_end_date' => null,
                    'reason' => $reason ?? $existing->reason,
                ])->save();
            } else {
                $this->createAssignment([
                    'employee_id' => $employeeId,
                    'geofence_id' => null,
                    'assignment_type' => 'permanent',
                    'validation_mode' => 'location_only',
                    'is_primary' => false,
                    'effective_start_date' => now()->toDateString(),
                    'effective_end_date' => null,
                    'reason' => $reason ?? 'Geofence boundary check disabled; location still required.',
                    'approved_by' => null,
                    'created_by' => (int) $actor->id,
                ]);
            }

            $event = 'location_only_enabled';
        } else {
            EmployeeGeofenceAssignment::query()
                ->where('employee_id', $employeeId)
                ->where('validation_mode', 'location_only')
                ->whereNotIn('status', ['removed', 'replaced'])
                ->update([
                    'status' => 'removed',
                    'effective_end_date' => now()->toDateString(),
                ]);

            $event = 'location_only_disabled';
        }

        $this->audit(
            $employeeId,
            $event,
            $previous,
            $this->currentAssignmentState($employeeId),
            $reason,
            (int) $actor->id,
            null,
        );

        EmployeeGeofenceResolver::forgetEmployeeCache($employeeId);

        return app(EmployeeGeofenceResolver::class)->resolveForAttendance($employeeId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCustomGeofence(array $data, User $actor): BranchGeofence
    {
        $ownership = ($data['ownership_type'] ?? 'shared') === 'employee_specific' ? 'employee_specific' : 'shared';

        return BranchGeofence::query()->create([
            'company_id' => (int) $data['company_id'],
            'branch_id' => (int) $data['branch_id'],
            'name' => (string) $data['name'],
            'address' => $data['address'] ?? null,
            'description' => $data['description'] ?? null,
            'ownership_type' => $ownership,
            'owner_employee_id' => $ownership === 'employee_specific' ? (int) ($data['owner_employee_id'] ?? 0) : null,
            'type' => $data['type'] ?? 'circle',
            'device_scope' => $data['device_scope'] ?? 'all_devices',
            'center_lat' => $data['center_lat'] ?? $data['latitude'] ?? null,
            'center_lng' => $data['center_lng'] ?? $data['longitude'] ?? null,
            'radius_meters' => $data['radius_meters'] ?? 100,
            'polygon_geojson' => $data['polygon_geojson'] ?? null,
            'is_active' => true,
            'status' => 'active',
            'enforcement_mode' => 'enforce',
            'created_by' => (int) $actor->id,
            'updated_by' => (int) $actor->id,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEmployeeSummaries(Collection $employeeIds): array
    {
        $resolver = app(EmployeeGeofenceResolver::class);

        return $employeeIds->map(function (int $employeeId) use ($resolver): array {
            return $resolver->employeeSummary($employeeId);
        })->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createAssignment(array $data): EmployeeGeofenceAssignment
    {
        if (($data['is_primary'] ?? false) && ! empty($data['geofence_id'])) {
            EmployeeGeofenceAssignment::query()
                ->where('employee_id', (int) $data['employee_id'])
                ->where('is_primary', true)
                ->whereNotIn('status', ['removed', 'replaced'])
                ->update(['is_primary' => false]);
        }

        return EmployeeGeofenceAssignment::query()->create([
            ...$data,
            'status' => 'active',
            'clock_in_applies' => $data['clock_in_applies'] ?? true,
            'clock_out_applies' => $data['clock_out_applies'] ?? true,
        ]);
    }

    private function endActiveAssignments(int $employeeId, User $actor, ?string $reason, ?int $approvedBy): void
    {
        EmployeeGeofenceAssignment::query()
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', ['removed', 'replaced', 'expired'])
            ->update([
                'status' => 'replaced',
                'effective_end_date' => now()->toDateString(),
            ]);
    }

    /**
     * @param  list<int>  $geofenceIds
     */
    private function removeGeofences(int $employeeId, array $geofenceIds, User $actor, ?string $reason, ?int $approvedBy): void
    {
        if ($geofenceIds === []) {
            return;
        }

        EmployeeGeofenceAssignment::query()
            ->where('employee_id', $employeeId)
            ->whereIn('geofence_id', $geofenceIds)
            ->whereNotIn('status', ['removed', 'replaced'])
            ->update([
                'status' => 'removed',
                'effective_end_date' => now()->toDateString(),
            ]);
    }

    private function changePrimary(int $employeeId, int $geofenceId, User $actor, ?string $reason, ?int $approvedBy): void
    {
        EmployeeGeofenceAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('is_primary', true)
            ->whereNotIn('status', ['removed', 'replaced'])
            ->update(['is_primary' => false]);

        EmployeeGeofenceAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('geofence_id', $geofenceId)
            ->whereNotIn('status', ['removed', 'replaced'])
            ->update(['is_primary' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentAssignmentState(int $employeeId): array
    {
        $rows = EmployeeGeofenceAssignment::query()
            ->with('geofence:id,name')
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', ['removed', 'replaced'])
            ->get();

        return [
            'primary_geofence_id' => $rows->firstWhere('is_primary', true)?->geofence_id,
            'geofence_ids' => $rows->pluck('geofence_id')->filter()->values()->all(),
            'validation_mode' => $rows->first()?->validation_mode,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     */
    private function audit(
        int $employeeId,
        string $event,
        ?array $previous,
        ?array $new,
        ?string $reason,
        int $changedBy,
        ?int $approvedBy,
    ): void {
        if (! \Illuminate\Support\Facades\Schema::hasTable('employee_geofence_assignment_audits')) {
            return;
        }

        EmployeeGeofenceAssignmentAudit::query()->create([
            'employee_id' => $employeeId,
            'event' => $event,
            'previous_state' => $previous,
            'new_state' => $new,
            'reason' => $reason,
            'changed_by' => $changedBy,
            'approved_by' => $approvedBy,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function actionApplies(array $payload, string $action): bool
    {
        $applicable = strtolower((string) ($payload['applicable_action'] ?? 'both'));
        if ($applicable === 'clock_in_only') {
            return $action === 'clock_in';
        }
        if ($applicable === 'clock_out_only') {
            return $action === 'clock_out';
        }

        return true;
    }
}
