<?php

namespace App\Support;

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
}
