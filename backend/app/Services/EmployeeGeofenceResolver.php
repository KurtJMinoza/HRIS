<?php

namespace App\Services;

use App\Models\BranchGeofence;
use App\Models\EmployeeGeofenceAssignment;
use App\Models\GeofenceGlobalSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class EmployeeGeofenceResolver
{
    public const CACHE_TTL_SECONDS = 600;

    public static function employeeCacheKey(int $employeeId): string
    {
        return GeofenceValidationService::scopedCacheKey("employee-geofence:{$employeeId}");
    }

    public static function forgetEmployeeCache(int $employeeId): void
    {
        $base = self::employeeCacheKey($employeeId);
        // Bump generation so dated action keys (…:YYYY-MM-DD:clock_in) stop hitting stale payloads.
        $genKey = $base.':gen';
        Cache::forever($genKey, ((int) Cache::get($genKey, 0)) + 1);
        Cache::forget($base);

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $today = now($tz);
        foreach (['clock_in', 'clock_out'] as $action) {
            foreach ([-1, 0, 1] as $dayOffset) {
                Cache::forget($base.':'.$today->copy()->addDays($dayOffset)->toDateString().':'.$action);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveForAttendance(int $employeeId, ?CarbonInterface $attendanceDateTime = null, string $attendanceAction = 'clock_in'): array
    {
        if (! Schema::hasTable('employee_geofence_assignments')) {
            return $this->legacyFallback($employeeId);
        }

        $at = $attendanceDateTime ?? now();
        $cacheKey = $this->resolveCacheKey($employeeId, $at, $attendanceAction);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, fn (): array => $this->resolveUncached($employeeId, $at, $attendanceAction));
    }

    private function resolveCacheKey(int $employeeId, CarbonInterface $at, string $attendanceAction): string
    {
        $base = self::employeeCacheKey($employeeId);
        $gen = (int) Cache::get($base.':gen', 0);

        return $base.':g'.$gen.':'.$at->toDateString().':'.$attendanceAction;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveUncached(int $employeeId, CarbonInterface $at, string $attendanceAction): array
    {
        $assignments = EmployeeGeofenceAssignment::query()
            ->with(['geofence' => fn ($q) => $q->with(['branch:id,name', 'company:id,name'])])
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', ['removed', 'replaced'])
            ->orderByDesc('is_primary')
            ->orderBy('effective_start_date')
            ->get();

        $effective = $assignments->filter(
            fn (EmployeeGeofenceAssignment $a): bool => $a->isEffectiveOn($at) && $a->appliesToAction($attendanceAction),
        );

        $exemption = $effective->first(
            fn (EmployeeGeofenceAssignment $a): bool => $a->assignment_type === 'exemption'
                || $a->validation_mode === 'no_geofence_required',
        );

        if ($exemption) {
            return [
                'employee_id' => $employeeId,
                'requires_geofence' => false,
                'validation_mode' => 'no_geofence_required',
                'primary_geofence_id' => null,
                'allowed_geofences' => [],
                'has_active_exemption' => true,
                'exemption' => $this->assignmentPayload($exemption),
                'no_assignment_policy' => null,
            ];
        }

        $geofenceAssignments = $effective->filter(
            fn (EmployeeGeofenceAssignment $a): bool => $a->geofence_id !== null && $a->assignment_type !== 'exemption',
        );

        $validationMode = $geofenceAssignments
            ->first(fn (EmployeeGeofenceAssignment $a): bool => $a->is_primary)?->validation_mode
            ?? $geofenceAssignments->first()?->validation_mode
            ?? 'any_assigned_geofence';

        $primary = $geofenceAssignments->first(fn (EmployeeGeofenceAssignment $a): bool => $a->is_primary);
        $allowed = [];
        $seenIds = [];

        foreach ($geofenceAssignments as $assignment) {
            $geofence = $assignment->geofence;
            if (! $geofence || ! $this->geofenceIsActive($geofence)) {
                continue;
            }
            if ($geofence->isEmployeeSpecific() && (int) ($geofence->owner_employee_id ?? 0) !== $employeeId) {
                continue;
            }

            $seenIds[(int) $geofence->id] = true;
            $allowed[] = $this->allowedGeofencePayload($geofence, $assignment);
        }

        // ponytail: include owned geofences even if assignment row was never written (older create bugs).
        foreach ($this->ownedActiveGeofences($employeeId) as $geofence) {
            $id = (int) $geofence->id;
            if (isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;
            $allowed[] = $this->allowedGeofencePayload($geofence, null);
        }

        if ($allowed === []) {
            return [
                'employee_id' => $employeeId,
                'requires_geofence' => $this->noAssignmentRequiresGeofence(),
                'validation_mode' => $validationMode,
                'primary_geofence_id' => null,
                'allowed_geofences' => [],
                'has_active_exemption' => false,
                'no_assignment_policy' => $this->noAssignmentPolicy(),
                'message' => 'No attendance location is assigned to your account. Please contact HR or your administrator.',
            ];
        }

        if ($validationMode === 'primary_geofence_only' && $primary) {
            $primaryId = (int) $primary->geofence_id;
            $allowed = array_values(array_filter($allowed, fn (array $g): bool => (int) $g['id'] === $primaryId));
        }

        return [
            'employee_id' => $employeeId,
            'requires_geofence' => true,
            'validation_mode' => $validationMode,
            'primary_geofence_id' => $primary ? (int) $primary->geofence_id : ($allowed[0]['id'] ?? null),
            'allowed_geofences' => $allowed,
            'has_active_exemption' => false,
            'no_assignment_policy' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function employeeSummary(int $employeeId, ?CarbonInterface $at = null): array
    {
        $resolved = $this->resolveForAttendance($employeeId, $at ?? now(), 'clock_in');
        $assignments = EmployeeGeofenceAssignment::query()
            ->with(['geofence:id,name,address,branch_id', 'geofence.branch:id,name', 'approver:id,first_name,last_name'])
            ->where('employee_id', $employeeId)
            ->orderByDesc('created_at')
            ->get();

        $at ??= now();
        $active = $assignments->filter(fn (EmployeeGeofenceAssignment $a): bool => $a->isEffectiveOn($at) && ! in_array($a->status, ['removed', 'replaced'], true));
        $upcoming = $assignments->filter(fn (EmployeeGeofenceAssignment $a): bool => $a->effective_start_date && $at->lt($a->effective_start_date->startOfDay()));
        $expired = $assignments->filter(fn (EmployeeGeofenceAssignment $a): bool => $a->effective_end_date && $at->gt($a->effective_end_date->endOfDay()));

        return [
            'resolved' => $resolved,
            'active_assignments' => $active->map(fn (EmployeeGeofenceAssignment $a): array => $this->assignmentPayload($a))->values()->all(),
            'upcoming_assignments' => $upcoming->map(fn (EmployeeGeofenceAssignment $a): array => $this->assignmentPayload($a))->values()->all(),
            'expired_assignments' => $expired->map(fn (EmployeeGeofenceAssignment $a): array => $this->assignmentPayload($a))->values()->all(),
        ];
    }

    public function noAssignmentPolicy(): string
    {
        if (! Schema::hasTable('geofence_global_settings') || ! Schema::hasColumn('geofence_global_settings', 'no_assignment_policy')) {
            return 'block';
        }

        return (string) (GeofenceGlobalSetting::query()->find(1)?->no_assignment_policy ?? 'use_branch_default');
    }

    public function noAssignmentRequiresGeofence(): bool
    {
        return match ($this->noAssignmentPolicy()) {
            'allow_with_warning' => false,
            'use_branch_default' => true,
            default => true,
        };
    }

    private function geofenceIsActive(BranchGeofence $geofence): bool
    {
        $status = $geofence->status ?? ($geofence->is_active ? 'active' : 'inactive');

        return $status === 'active' || ($geofence->is_active && $status !== 'inactive' && $status !== 'draft');
    }

    /**
     * @return \Illuminate\Support\Collection<int, BranchGeofence>
     */
    private function ownedActiveGeofences(int $employeeId)
    {
        if (! Schema::hasColumn('branch_geofences', 'owner_employee_id')) {
            return collect();
        }

        return BranchGeofence::query()
            ->with(['branch:id,name', 'company:id,name'])
            ->where('owner_employee_id', $employeeId)
            ->get()
            ->filter(fn (BranchGeofence $geofence): bool => $this->geofenceIsActive($geofence))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function allowedGeofencePayload(BranchGeofence $geofence, ?EmployeeGeofenceAssignment $assignment): array
    {
        return [
            'id' => (int) $geofence->id,
            'name' => $geofence->name,
            'address' => $geofence->address,
            'latitude' => $geofence->center_lat !== null ? (float) $geofence->center_lat : null,
            'longitude' => $geofence->center_lng !== null ? (float) $geofence->center_lng : null,
            'radius_meters' => $geofence->radius_meters !== null ? (int) $geofence->radius_meters : null,
            'type' => $geofence->type,
            'polygon_geojson' => $geofence->polygon_geojson,
            'device_scope' => $geofence->device_scope ?? 'all_devices',
            'assignment_type' => $assignment?->assignment_type ?? 'permanent',
            'is_primary' => (bool) ($assignment?->is_primary ?? false),
            'ownership_type' => $geofence->ownership_type ?? 'shared',
            'branch_id' => $geofence->branch_id !== null ? (int) $geofence->branch_id : null,
            'branch_name' => $geofence->branch?->name,
            'company_name' => $geofence->company?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentPayload(EmployeeGeofenceAssignment $assignment): array
    {
        return [
            'id' => (int) $assignment->id,
            'employee_id' => (int) $assignment->employee_id,
            'geofence_id' => $assignment->geofence_id !== null ? (int) $assignment->geofence_id : null,
            'geofence_name' => $assignment->geofence?->name,
            'assignment_type' => $assignment->assignment_type,
            'validation_mode' => $assignment->validation_mode,
            'is_primary' => (bool) $assignment->is_primary,
            'effective_start_date' => $assignment->effective_start_date?->toDateString(),
            'effective_end_date' => $assignment->effective_end_date?->toDateString(),
            'clock_in_applies' => (bool) $assignment->clock_in_applies,
            'clock_out_applies' => (bool) $assignment->clock_out_applies,
            'status' => $assignment->status,
            'reason' => $assignment->reason,
            'approved_by' => $assignment->approved_by,
            'approved_by_name' => $assignment->approver
                ? trim(($assignment->approver->first_name ?? '').' '.($assignment->approver->last_name ?? ''))
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyFallback(int $employeeId): array
    {
        return [
            'employee_id' => $employeeId,
            'requires_geofence' => true,
            'validation_mode' => 'any_assigned_geofence',
            'primary_geofence_id' => null,
            'allowed_geofences' => [],
            'has_active_exemption' => false,
            'legacy_mode' => true,
        ];
    }
}
