<?php

namespace App\Contracts;

use App\Models\User;
use Carbon\Carbon;

/**
 * Narrow surface used by refund/adjustment flows — keeps callers off the 5k-line payroll engine file.
 */
interface PayrollDayComputation extends ClearsPayrollRuntimeCaches
{
    public function getTimezone(): string;

    /**
     * Historical schedule + daily rate for one affected date.
     *
     * @return array{effective_schedule: array, daily_rate: float, timezone: string}
     */
    public function resolveSingleDayComputationContext(User $user, Carbon $date): array;

    /**
     * @return array<string, mixed>
     */
    public function computeEmployeePayroll(User $user, Carbon $from, Carbon $to, ?float $overrideDailyRate = null, array $periodContext = []): array;

    /**
     * @return array<string, mixed>
     */
    public function computeDayPayroll(
        User $user,
        string $dateKey,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        array $effectiveSchedule,
        float $dailyRate,
        ?string $tz = null
    ): array;
}
