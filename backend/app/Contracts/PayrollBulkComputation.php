<?php

namespace App\Contracts;

use Carbon\Carbon;

/**
 * Bulk payslip / finalize paths that prefetch attendance once per pay window.
 */
interface PayrollBulkComputation extends PayrollDayComputation
{
    /**
     * @param  list<int>  $userIds
     * @return array<string, mixed>
     */
    public function beginBulkPayrollAttendancePrefetch(
        array $userIds,
        Carbon $from,
        Carbon $to,
        ?int $companyId = null,
        ?int $payrollBatchRunId = null
    ): array;

    public function endBulkPayrollAttendancePrefetch(): void;
}
