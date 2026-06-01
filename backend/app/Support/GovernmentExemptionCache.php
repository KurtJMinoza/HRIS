<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class GovernmentExemptionCache
{
    public static function forgetForEmployee(int $employeeId): void
    {
        Cache::forget('government_exemption:'.$employeeId);
        Cache::forget('payroll:employee:'.$employeeId);
    }

    public static function clearPayrollCaches(?int $employeeId = null): void
    {
        PayrollCacheInvalidator::clear('government_exemption_updated', [
            'employee_id' => $employeeId,
        ]);

        foreach ([
            'payroll:draft',
            'payroll:payslip:preview',
            'payroll:summary',
            'recent_payslips',
        ] as $key) {
            Cache::forget($key);
        }

        if ($employeeId !== null) {
            self::forgetForEmployee($employeeId);
        }
    }
}
