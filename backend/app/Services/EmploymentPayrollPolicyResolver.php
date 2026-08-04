<?php

namespace App\Services;

use App\Models\EmploymentPayrollSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmploymentPayrollPolicyResolver
{
    public const CACHE_PREFIX = 'employment:payroll-policy:';

    public function __construct(
        private readonly EmploymentTypeResolver $employmentTypeResolver,
    ) {}

    /**
     * @return array{
     *     company_id: ?int,
     *     employment_type: string,
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }
     */
    public function resolveForEmployee(User $employee, ?int $companyId = null): array
    {
        $employmentType = $this->employmentTypeResolver->resolveLaborEmploymentType($employee);
        $resolvedCompanyId = $companyId ?? ($employee->getEffectiveCompanyId() ?: null);

        return $this->resolve($resolvedCompanyId, $employmentType);
    }

    /**
     * @return array{
     *     company_id: ?int,
     *     employment_type: string,
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }
     */
    public function resolve(?int $companyId, string $employmentType): array
    {
        $employmentType = EmploymentPayrollSetting::normalizeEmploymentType($employmentType);
        $key = $this->cacheKey($companyId, $employmentType);

        try {
            if (function_exists('app') && app()->bound('cache')) {
                return Cache::remember($key, now()->addMinutes(30), fn (): array => $this->loadPolicy($companyId, $employmentType));
            }
        } catch (\Throwable) {
            // Unit tests / no cache container — fall through.
        }

        return $this->loadPolicy($companyId, $employmentType);
    }

    /**
     * @return array<string, array{
     *     employment_type: string,
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }>
     */
    public function resolveAllForCompany(?int $companyId = null): array
    {
        $settings = [];
        foreach (EmploymentPayrollSetting::EMPLOYMENT_TYPES as $employmentType) {
            $policy = $this->resolve($companyId, $employmentType);
            unset($policy['company_id']);
            $settings[$employmentType] = $policy;
        }

        return $settings;
    }

    public function setting(?int $companyId, string $employmentType): EmploymentPayrollSetting
    {
        return EmploymentPayrollSetting::forCompanyAndType($companyId, $employmentType);
    }

    public function forget(?int $companyId = null, ?string $employmentType = null): void
    {
        try {
            if (! function_exists('app') || ! app()->bound('cache')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $types = $employmentType !== null
            ? [EmploymentPayrollSetting::normalizeEmploymentType($employmentType)]
            : EmploymentPayrollSetting::EMPLOYMENT_TYPES;

        foreach ($types as $type) {
            Cache::forget($this->cacheKey($companyId, $type));
            Cache::forget($this->cacheKey(null, $type));
        }

        // Company-scoped resolve() caches inherit the global row. Updating Global (or any
        // company) must drop every company's inherited cache, not only override rows.
        foreach ($this->companyIdsForCacheInvalidation($companyId) as $id) {
            foreach ($types as $type) {
                Cache::forget($this->cacheKey($id, $type));
            }
        }
    }

    /**
     * @return list<int>
     */
    private function companyIdsForCacheInvalidation(?int $companyId): array
    {
        if ($companyId !== null) {
            return [$companyId];
        }

        $ids = [];

        try {
            if (Schema::hasTable('companies')) {
                $ids = array_merge($ids, DB::table('companies')->pluck('id')->map(fn ($id) => (int) $id)->all());
            }
        } catch (\Throwable) {
            // Schema/cache probe may be unavailable in unit tests.
        }

        try {
            $ids = array_merge(
                $ids,
                EmploymentPayrollSetting::query()
                    ->whereNotNull('company_id')
                    ->pluck('company_id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
            );
        } catch (\Throwable) {
            // Table may be missing in unit tests.
        }

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }

    /**
     * @return array{
     *     company_id: ?int,
     *     employment_type: string,
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }
     */
    private function loadPolicy(?int $companyId, string $employmentType): array
    {
        $setting = EmploymentPayrollSetting::forCompanyAndType($companyId, $employmentType);

        return array_merge(
            [
                'company_id' => $companyId,
                'employment_type' => $employmentType,
            ],
            $setting->toPolicyArray()
        );
    }

    private function cacheKey(?int $companyId, string $employmentType): string
    {
        return self::CACHE_PREFIX
            .($companyId === null ? 'global' : (string) $companyId)
            .':'
            .$employmentType;
    }
}
