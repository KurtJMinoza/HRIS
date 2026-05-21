<?php

namespace App\Services;

use App\Models\Department;
use App\Models\SectionUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OrgTeamLeaderService
{
    /**
     * @param  list<int>  $employeeIds
     */
    public function syncDepartmentTeamLeaders(Department $department, array $employeeIds): void
    {
        $ids = $this->normalizeEmployeeIds($employeeIds);
        $this->validateDepartmentTeamLeaders($department, $ids);
        $department->teamLeaders()->sync($ids);
        foreach ($ids as $uid) {
            \App\Support\EmployeeProfileCache::invalidate($uid);
        }
    }

    /**
     * @param  list<int>  $employeeIds
     */
    public function syncSectionTeamLeaders(SectionUnit $section, array $employeeIds): void
    {
        $ids = $this->normalizeEmployeeIds($employeeIds);
        $this->validateSectionTeamLeaders($section, $ids);
        $section->teamLeaders()->sync($ids);
        foreach ($ids as $uid) {
            \App\Support\EmployeeProfileCache::invalidate($uid);
        }
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function validateDepartmentTeamLeaders(Department $department, array $employeeIds): void
    {
        if ($employeeIds === []) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $employeeIds)
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->get()
            ->keyBy('id');

        foreach ($employeeIds as $index => $userId) {
            $field = "team_leader_ids.{$index}";
            $user = $users->get($userId);
            if (! $user) {
                throw ValidationException::withMessages([
                    'team_leader_ids' => ['One or more selected team leaders are not eligible employees.'],
                ]);
            }
            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    $field => ['The selected employee must be active to be assigned as team leader.'],
                ]);
            }

            $belongsToDepartment = (int) $user->department_id === (int) $department->id;
            $isImmediateHead = (int) $department->department_head_id === (int) $userId;

            if (! $belongsToDepartment && ! $isImmediateHead) {
                throw ValidationException::withMessages([
                    $field => ['The selected employee must belong to this department or be its immediate head.'],
                ]);
            }

            if ($user->branch_id !== null && $department->branch_id !== null
                && (int) $user->branch_id !== (int) $department->branch_id) {
                throw ValidationException::withMessages([
                    $field => ['The selected employee must belong to the same branch as this department.'],
                ]);
            }

            $headCompanyId = $user->getEffectiveCompanyId();
            $deptCompanyId = $department->company_id ?? $department->branch?->company_id;
            if ($headCompanyId !== null && $deptCompanyId !== null && (int) $headCompanyId !== (int) $deptCompanyId) {
                throw ValidationException::withMessages([
                    $field => ['The selected employee is assigned to another company and cannot be a team leader here.'],
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function validateSectionTeamLeaders(SectionUnit $section, array $employeeIds): void
    {
        if ($employeeIds === []) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $employeeIds)
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->get()
            ->keyBy('id');

        foreach ($employeeIds as $index => $userId) {
            $field = "team_leader_ids.{$index}";
            $user = $users->get($userId);
            if (! $user) {
                throw ValidationException::withMessages([
                    'team_leader_ids' => ['One or more selected team leaders are not eligible employees.'],
                ]);
            }
            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    $field => ['The selected employee must be active to be assigned as team leader.'],
                ]);
            }

            $belongsToSection = (int) $user->section_unit_id === (int) $section->id;
            $belongsToDepartment = $section->department_id !== null
                && (int) $user->department_id === (int) $section->department_id;
            $isSectionHead = (int) $section->section_unit_head_id === (int) $userId;
            $isDepartmentHead = $section->department_id !== null
                && Department::query()
                    ->whereKey($section->department_id)
                    ->where('department_head_id', $userId)
                    ->exists();

            if (! $belongsToSection && ! $belongsToDepartment && ! $isSectionHead && ! $isDepartmentHead) {
                throw ValidationException::withMessages([
                    $field => ['The selected employee must belong to this section/unit or its parent department.'],
                ]);
            }

            $headCompanyId = $user->getEffectiveCompanyId();
            if ($headCompanyId !== null && $section->company_id !== null && (int) $headCompanyId !== (int) $section->company_id) {
                throw ValidationException::withMessages([
                    $field => ['The selected employee is assigned to another company and cannot be a team leader here.'],
                ]);
            }
        }
    }

    /**
     * @param  list<int|string|null>  $employeeIds
     * @return list<int>
     */
    private function normalizeEmployeeIds(array $employeeIds): array
    {
        $ids = collect($employeeIds)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'team_leader_ids' => ['Duplicate team leader selections are not allowed.'],
            ]);
        }

        return $ids;
    }

    /**
     * @return array{
     *   team_leader_ids: list<int>,
     *   team_leaders: list<array{id: int, name: string, profile_image: ?string}>,
     *   team_leader_names: string,
     *   team_leader_display: string
     * }
     */
    public function departmentTeamLeaderPayload(Department $department): array
    {
        /** @var Collection<int, User> $leaders */
        $leaders = $department->relationLoaded('teamLeaders')
            ? $department->teamLeaders
            : $department->teamLeaders()->orderBy('users.last_name')->orderBy('users.first_name')->get();

        return $this->formatTeamLeaderPayload($leaders);
    }

    /**
     * @return array{
     *   team_leader_ids: list<int>,
     *   team_leaders: list<array{id: int, name: string, profile_image: ?string}>,
     *   team_leader_names: string,
     *   team_leader_display: string
     * }
     */
    public function sectionTeamLeaderPayload(SectionUnit $section): array
    {
        /** @var Collection<int, User> $leaders */
        $leaders = $section->relationLoaded('teamLeaders')
            ? $section->teamLeaders
            : $section->teamLeaders()->orderBy('users.last_name')->orderBy('users.first_name')->get();

        return $this->formatTeamLeaderPayload($leaders);
    }

    /**
     * @param  Collection<int, User>  $leaders
     * @return array{
     *   team_leader_ids: list<int>,
     *   team_leaders: list<array{id: int, name: string, profile_image: ?string}>,
     *   team_leader_names: string,
     *   team_leader_display: string
     * }
     */
    private function formatTeamLeaderPayload(Collection $leaders): array
    {
        $rows = $leaders->map(fn (User $user): array => [
            'id' => (int) $user->id,
            'name' => $user->display_name,
            'profile_image' => $user->profile_image_url,
        ])->values()->all();

        $names = collect($rows)->pluck('name')->filter()->values();

        return [
            'team_leader_ids' => collect($rows)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'team_leaders' => $rows,
            'team_leader_names' => $names->join(', '),
            'team_leader_display' => $names->join(' / '),
        ];
    }
}
