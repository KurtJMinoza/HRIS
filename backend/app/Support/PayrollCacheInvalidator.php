<?php

namespace App\Support;

use App\Services\ExecomPayrollPolicyResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PayrollCacheInvalidator
{
    /**
     * Cache keys that affect payroll roster membership, draft totals, recent batches, and reports.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'payroll:draft:list',
            'payroll:draft:summary',
            'payroll:employee-count',
            'payroll:recent-payslips',
            'payroll:reports',
            'payroll:execom:draft:list',
            'payroll:execom:summary',
        ];
    }

    /**
     * EXECOM-only draft/summary keys (does not touch Regular Payroll draft keys).
     *
     * @return list<string>
     */
    public static function execomKeys(): array
    {
        return [
            'payroll:execom:draft:list',
            'payroll:execom:summary',
            'execom:payroll-preview:list',
            'execom:payroll-draft:list',
            'execom:payslip:list',
            'execom:attendance-summary:list',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function clear(string $reason, array $context = []): void
    {
        foreach (self::keys() as $key) {
            Cache::forget($key);
        }

        Log::info('Payroll cache invalidated', [
            'reason' => $reason,
            'keys' => self::keys(),
            ...$context,
        ]);
    }

    /**
     * Invalidate EXECOM policy + draft caches without clearing Regular Payroll draft keys.
     *
     * @param  array<string, mixed>  $context
     */
    public static function clearExecom(string $reason, array $context = [], ?int $companyId = null): void
    {
        foreach (self::execomKeys() as $key) {
            Cache::forget($key);
        }

        try {
            app(ExecomPayrollPolicyResolver::class)->forget($companyId);
        } catch (\Throwable) {
            // Resolver unavailable in some test contexts.
        }

        Log::info('EXECOM payroll cache invalidated', [
            'reason' => $reason,
            'keys' => self::execomKeys(),
            'company_id' => $companyId,
            ...$context,
        ]);
    }
}
