<?php

namespace App\Services;

use App\Models\PayrollBatchRun;
use App\Models\PayrollEmployee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Cache;

/** Shared finalized-payroll freeze lookup for standard and EXECOM payroll. */
final class PayrollFreezeService
{
    public const LOCK_MESSAGE = 'This payroll period has already been finalized and is locked.';

    public const APPROVAL_LOCK_MESSAGE = 'Cannot approve request. The payroll period has already been finalized.';

    private const CACHE_VERSION_KEY = 'payroll_freeze:version';

    /** @return array{frozen:bool,payroll_type:?string,payroll_run_id:?int,period_start:?string,period_end:?string,finalized_at:?string,reason:?string} */
    public function isDateFrozenForEmployee(int $employeeId, Carbon|string $date): array
    {
        $dateKey = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return $this->isWindowFrozenForEmployee($employeeId, $dateKey, $dateKey);
    }

    public function isPayrollLocked(int $employeeId, Carbon|string $requestDate): bool
    {
        return (bool) $this->isDateFrozenForEmployee($employeeId, $requestDate)['frozen'];
    }

    /**
     * @throws \RuntimeException
     */
    public function assertMutableForUserWindow(int $employeeId, Carbon|string $from, Carbon|string $to): void
    {
        if ($this->isWindowFrozenForEmployee($employeeId, $from, $to)['frozen']) {
            throw new \RuntimeException(self::LOCK_MESSAGE);
        }
    }

    /** @return array{frozen:bool,payroll_type:?string,payroll_run_id:?int,period_start:?string,period_end:?string,finalized_at:?string,reason:?string} */
    public function isWindowFrozenForEmployee(int $employeeId, Carbon|string $from, Carbon|string $to): array
    {
        $fromKey = $from instanceof Carbon ? $from->toDateString() : Carbon::parse($from)->toDateString();
        $toKey = $to instanceof Carbon ? $to->toDateString() : Carbon::parse($to)->toDateString();
        if ($toKey < $fromKey) {
            [$fromKey, $toKey] = [$toKey, $fromKey];
        }

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);

        return Cache::remember("payroll_freeze:{$version}:{$employeeId}:{$fromKey}:{$toKey}", now()->addMinutes(10), function () use ($employeeId, $fromKey, $toKey): array {
            $employeePayroll = PayrollEmployee::query()
                ->join('payroll_batch_runs', 'payroll_batch_runs.id', '=', 'payroll_employees.payroll_batch_run_id')
                ->where('payroll_employees.user_id', $employeeId)
                ->where('payroll_employees.status', PayrollEmployee::STATUS_FINALIZED)
                ->where('payroll_batch_runs.status', PayrollBatchRun::STATUS_FINALIZED)
                ->whereIn('payroll_batch_runs.payroll_module', [PayrollBatchRun::MODULE_STANDARD, PayrollBatchRun::MODULE_EXECOM])
                ->whereDate('payroll_employees.pay_period_start', '<=', $toKey)
                ->whereDate('payroll_employees.pay_period_end', '>=', $fromKey)
                ->orderByDesc('payroll_batch_runs.finalized_at')
                ->orderByDesc('payroll_batch_runs.id')
                ->select([
                    'payroll_employees.pay_period_start',
                    'payroll_employees.pay_period_end',
                    'payroll_batch_runs.id as payroll_run_id',
                    'payroll_batch_runs.payroll_module',
                    'payroll_batch_runs.finalized_at',
                ])
                ->first();

            if ($employeePayroll !== null) {
                return $this->frozenResult(
                    (string) $employeePayroll->payroll_module,
                    (int) $employeePayroll->payroll_run_id,
                    Carbon::parse($employeePayroll->pay_period_start)->toDateString(),
                    Carbon::parse($employeePayroll->pay_period_end)->toDateString(),
                    $employeePayroll->finalized_at ? Carbon::parse($employeePayroll->finalized_at)->toIso8601String() : null,
                );
            }

            // Backward compatibility for finalized payrolls created before payroll_employees existed.
            $payslip = Payslip::query()
                ->where('user_id', $employeeId)
                ->whereIn('status', Payslip::lockingStatuses())
                ->whereDate('pay_period_start', '<=', $toKey)
                ->whereDate('pay_period_end', '>=', $fromKey)
                ->orderByDesc('id')
                ->first(['payroll_batch_run_id', 'payroll_module', 'pay_period_start', 'pay_period_end', 'finalized_at']);
            if ($payslip !== null) {
                return $this->frozenResult(
                    (string) ($payslip->payroll_module ?: PayrollBatchRun::MODULE_STANDARD),
                    $payslip->payroll_batch_run_id ? (int) $payslip->payroll_batch_run_id : null,
                    $payslip->pay_period_start->toDateString(),
                    $payslip->pay_period_end->toDateString(),
                    $payslip->finalized_at?->toIso8601String(),
                );
            }

            $period = PayrollPeriod::query()
                ->where('user_id', $employeeId)
                ->where('status', PayrollPeriod::STATUS_LOCKED)
                ->whereDate('from_date', '<=', $toKey)
                ->whereDate('to_date', '>=', $fromKey)
                ->orderByDesc('id')
                ->first(['from_date', 'to_date']);
            if ($period !== null) {
                return $this->frozenResult(PayrollBatchRun::MODULE_STANDARD, null, $period->from_date->toDateString(), $period->to_date->toDateString(), null);
            }

            return $this->notFrozenResult();
        });
    }

    /** @return list<string> */
    public function frozenDatesForEmployee(int $employeeId, Carbon|string $from, Carbon|string $to): array
    {
        $dates = [];
        foreach (CarbonPeriod::create(Carbon::parse($from)->startOfDay(), Carbon::parse($to)->startOfDay()) as $date) {
            if ($this->isDateFrozenForEmployee($employeeId, $date)['frozen']) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    public static function invalidateCache(): void
    {
        if (! Cache::has(self::CACHE_VERSION_KEY)) {
            Cache::forever(self::CACHE_VERSION_KEY, 2);
            return;
        }
        Cache::increment(self::CACHE_VERSION_KEY);
    }

    private function frozenResult(string $module, ?int $runId, string $start, string $end, ?string $finalizedAt): array
    {
        $type = $module === PayrollBatchRun::MODULE_EXECOM ? 'execom' : 'regular';
        return [
            'frozen' => true,
            'payroll_type' => $type,
            'payroll_run_id' => $runId,
            'period_start' => $start,
            'period_end' => $end,
            'finalized_at' => $finalizedAt,
            'reason' => $type === 'execom'
                ? 'This date is locked because EXECOM payroll has already been finalized for this cutoff.'
                : 'This date is locked because regular payroll has already been finalized for this cutoff.',
        ];
    }

    private function notFrozenResult(): array
    {
        return ['frozen' => false, 'payroll_type' => null, 'payroll_run_id' => null, 'period_start' => null, 'period_end' => null, 'finalized_at' => null, 'reason' => null];
    }
}
