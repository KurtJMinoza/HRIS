<?php

namespace App\Services;

use App\Models\User;
use App\Support\HeadAssignmentEmployeeSearchCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HeadAssignmentEmployeeSearchService
{
    private const CACHE_TTL_SECONDS = 45;

    private const MAX_RESULTS = 50;

    private const DEFAULT_BROWSE_LIMIT = 50;

    /**
     * @return array{employees: list<array<string, mixed>>}
     */
    public function search(Request $request): array
    {
        $q = trim((string) $request->query('q', $request->query('search', '')));
        $companyId = $request->filled('company_id') ? (int) $request->query('company_id') : null;
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $departmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;
        $includeCrossCompany = $request->boolean('include_cross_company', true);
        $activeOnly = $request->boolean('active_only', true);

        $cacheKey = 'head_assignment_employee_search:'.HeadAssignmentEmployeeSearchCache::version().':'.md5(json_encode([
            'q' => mb_strtolower($q),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'include_cross_company' => $includeCrossCompany,
            'active_only' => $activeOnly,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, now()->addSeconds(self::CACHE_TTL_SECONDS), function () use (
            $q,
            $companyId,
            $branchId,
            $departmentId,
            $includeCrossCompany,
            $activeOnly,
        ): array {
            $query = $this->baseQuery($activeOnly);
            $this->applyOptionalOrgFilters($query, $companyId, $branchId, $departmentId, $includeCrossCompany);
            $this->applyTokenSearch($query, $q);

            $limit = $q === '' ? self::DEFAULT_BROWSE_LIMIT : self::MAX_RESULTS;

            $users = $query
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->orderBy('middle_name')
                ->orderBy('id')
                ->limit($limit)
                ->get();

            return [
                'employees' => $users->map(fn (User $user): array => $this->formatRow($user))->values()->all(),
            ];
        });
    }

  /**
     * @return Builder<User>
     */
    private function baseQuery(bool $activeOnly): Builder
    {
        $query = User::query()
            ->roster()
            ->select([
                'id',
                'employee_code',
                'name',
                'first_name',
                'middle_name',
                'last_name',
                'suffix',
                'email',
                'position',
                'employment_status',
                'is_active',
                'profile_image',
                'company_id',
                'branch_id',
                'department_id',
            ])
            ->with([
                'company:id,name',
                'departmentRelation:id,name',
            ]);

        if ($activeOnly) {
            $query->active();
        }

        return $query;
    }

    private function applyOptionalOrgFilters(
        Builder $query,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        bool $includeCrossCompany,
    ): void {
        if ($departmentId !== null) {
            $query->where(function (Builder $scope) use ($departmentId): void {
                $scope->where('department_id', $departmentId)
                    ->orWhereHas('sectionUnit', fn (Builder $section) => $section->where('department_id', $departmentId));
            });
        }

        if ($branchId !== null) {
            $query->where(function (Builder $scope) use ($branchId): void {
                $scope->where('branch_id', $branchId)
                    ->orWhereHas('departmentRelation', fn (Builder $department) => $department->where('branch_id', $branchId))
                    ->orWhereHas('division', fn (Builder $division) => $division->where('branch_id', $branchId))
                    ->orWhereHas('sectionUnit', fn (Builder $section) => $section->where('branch_id', $branchId));
            });
        }

        if (! $includeCrossCompany && $companyId !== null) {
            $query->where(function (Builder $scope) use ($companyId): void {
                $scope->where('company_id', $companyId)
                    ->orWhereHas('branch', fn (Builder $branch) => $branch->where('company_id', $companyId))
                    ->orWhereHas('departmentRelation', fn (Builder $department) => $department
                        ->where('company_id', $companyId)
                        ->orWhereHas('branch', fn (Builder $branch) => $branch->where('company_id', $companyId)))
                    ->orWhereHas('division', fn (Builder $division) => $division
                        ->where('company_id', $companyId)
                        ->orWhereHas('branch', fn (Builder $branch) => $branch->where('company_id', $companyId)))
                    ->orWhereHas('sectionUnit', fn (Builder $section) => $section
                        ->where('company_id', $companyId)
                        ->orWhereHas('branch', fn (Builder $branch) => $branch->where('company_id', $companyId)));
            });
        }
    }

    private function applyTokenSearch(Builder $query, string $q): void
    {
        if ($q === '') {
            return;
        }

        $tokens = preg_split('/\s+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return;
        }

        foreach ($tokens as $token) {
            $like = '%'.$token.'%';
            $query->where(function (Builder $sub) use ($like): void {
                $sub->whereRaw('LOWER(COALESCE(first_name, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(middle_name, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(last_name, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(name, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(employee_code, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(email, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(position, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(department, "")) LIKE ?', [$like])
                    ->orWhereHas('company', fn (Builder $company) => $company->whereRaw('LOWER(name) LIKE ?', [$like]))
                    ->orWhereHas('departmentRelation', fn (Builder $department) => $department->whereRaw('LOWER(name) LIKE ?', [$like]));
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRow(User $user): array
    {
        $fullName = $user->display_name ?: trim(implode(' ', array_filter([
            $user->last_name,
            $user->first_name,
            $user->middle_name,
            $user->suffix,
        ])));

        $departmentName = $user->departmentRelation?->name ?? $user->department;
        $companyName = $user->company?->name;

        return [
            'employee_id' => (int) $user->id,
            'id' => (int) $user->id,
            'employee_number' => $user->employee_code,
            'employee_code' => $user->employee_code,
            'full_name' => $fullName,
            'name' => $fullName,
            'display_name' => $fullName,
            'formatted_name' => $user->formatted_name,
            'company_name' => $companyName,
            'department_name' => $departmentName,
            'department' => $departmentName,
            'position' => $user->position,
            'employment_status' => $user->employment_status,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'profile_image' => $user->profile_image,
            'profile_image_url' => $user->profile_image_url,
            'initials' => $this->initials($fullName),
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '?';
        }
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
    }
}
