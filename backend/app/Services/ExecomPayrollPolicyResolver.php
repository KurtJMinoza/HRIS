<?php

namespace App\Services;

use App\Models\ExecomPayrollSetting;
use Illuminate\Support\Facades\Cache;

class ExecomPayrollPolicyResolver
{
    public const CACHE_PREFIX = 'execom:payroll-policy:';

    /**
     * @return array{
     *     company_id: ?int,
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }
     */
    public function resolve(?int $companyId = null): array
    {
        $key = $this->cacheKey($companyId);

        try {
            if (function_exists('app') && app()->bound('cache')) {
                return Cache::remember($key, now()->addMinutes(30), fn (): array => $this->loadPolicy($companyId));
            }
        } catch (\Throwable) {
            // Unit tests / no cache container — fall through.
        }

        return $this->loadPolicy($companyId);
    }

    public function setting(?int $companyId = null): ExecomPayrollSetting
    {
        return ExecomPayrollSetting::forCompany($companyId);
    }

    public function forget(?int $companyId = null): void
    {
        try {
            if (! function_exists('app') || ! app()->bound('cache')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        if ($companyId === null) {
            Cache::forget($this->cacheKey(null));

            // ponytail: only drop known company rows we can see; wildcards need tagged cache.
            ExecomPayrollSetting::query()
                ->whereNotNull('company_id')
                ->pluck('company_id')
                ->unique()
                ->each(fn ($id) => Cache::forget($this->cacheKey((int) $id)));

            return;
        }

        Cache::forget($this->cacheKey($companyId));
        Cache::forget($this->cacheKey(null));
    }

    /**
     * @return array{
     *     company_id: ?int,
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }
     */
    private function loadPolicy(?int $companyId): array
    {
        $setting = ExecomPayrollSetting::forCompany($companyId);

        return array_merge(
            ['company_id' => $companyId],
            $setting->toPolicyArray()
        );
    }

    private function cacheKey(?int $companyId): string
    {
        return self::CACHE_PREFIX.($companyId === null ? 'global' : (string) $companyId);
    }
}
