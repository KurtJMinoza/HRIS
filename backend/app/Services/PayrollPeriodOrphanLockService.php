<?php

namespace App\Services;

use App\Models\PayrollBatchRun;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Payroll "locks" are not a separate table: they are finalized {@see Payslip} rows and
 * {@see PayrollPeriod} rows with status locked. If {@see PayrollBatchRun} rows are removed
 * while payslips stay finalized, the app incorrectly blocks edits — reconcile demotes those
 * payslips and unlocks matching periods when no supporting finalized batch exists.
 */
final class PayrollPeriodOrphanLockService
{
    public static function isAutoReconcileEnabled(): bool
    {
        return (bool) config('payroll.auto_reconcile_orphan_locks', true);
    }

    /**
     * A finalized batch authorizes finalized payslips when company + pay window match and each
     * scope column on the batch is either null (whole scope) or equals the employee/payslip slice.
     */
    public static function finalizedBatchSupportsPayslip(Payslip $slip): bool
    {
        $cid = (int) $slip->company_id;
        if ($cid <= 0) {
            $owner = User::query()->find((int) $slip->user_id);
            $cid = (int) ($owner?->getEffectiveCompanyId() ?? $owner?->company_id ?? 0);
        }
        if ($cid <= 0) {
            return false;
        }

        $ps = $slip->pay_period_start instanceof \Carbon\CarbonInterface
            ? $slip->pay_period_start->toDateString()
            : (string) $slip->pay_period_start;
        $pe = $slip->pay_period_end instanceof \Carbon\CarbonInterface
            ? $slip->pay_period_end->toDateString()
            : (string) $slip->pay_period_end;

        return self::finalizedBatchExistsForScope(
            $cid,
            $ps,
            $pe,
            $slip->branch_id !== null ? (int) $slip->branch_id : null,
            $slip->department_id !== null ? (int) $slip->department_id : null,
            (int) $slip->user_id
        );
    }

    public static function finalizedBatchSupportsLockedPeriod(User $user, PayrollPeriod $period): bool
    {
        $cid = (int) ($user->getEffectiveCompanyId() ?? $user->company_id ?? 0);
        if ($cid <= 0) {
            return false;
        }

        $ps = $period->from_date->toDateString();
        $pe = $period->to_date->toDateString();

        return self::finalizedBatchExistsForScope(
            $cid,
            $ps,
            $pe,
            $user->branch_id !== null ? (int) $user->branch_id : null,
            $user->department_id !== null ? (int) $user->department_id : null,
            (int) $user->id
        );
    }

    private static function finalizedBatchExistsForScope(
        int $companyId,
        string $payPeriodStart,
        string $payPeriodEnd,
        ?int $branchId,
        ?int $departmentId,
        int $employeeUserId
    ): bool {
        $q = PayrollBatchRun::query()
            ->where('company_id', $companyId)
            ->where('status', PayrollBatchRun::STATUS_FINALIZED)
            ->whereDate('pay_period_start', $payPeriodStart)
            ->whereDate('pay_period_end', $payPeriodEnd);

        if ($branchId !== null && $branchId > 0) {
            $q->where(function (Builder $w) use ($branchId) {
                $w->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });
        }

        if ($departmentId !== null && $departmentId > 0) {
            $q->where(function (Builder $w) use ($departmentId) {
                $w->whereNull('department_id')->orWhere('department_id', $departmentId);
            });
        }

        $q->where(function (Builder $w) use ($employeeUserId) {
            $w->whereNull('employee_id')->orWhere('employee_id', $employeeUserId);
        });

        return $q->exists();
    }

    public static function reconcileForUserWindow(int $userId, Carbon $from, Carbon $to, bool $force = false): void
    {
        if (! $force && ! self::isAutoReconcileEnabled()) {
            return;
        }

        $fs = $from->toDateString();
        $te = $to->toDateString();

        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        $finalizedPayslips = Payslip::query()
            ->where('user_id', $userId)
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereDate('pay_period_start', '<=', $te)
            ->whereDate('pay_period_end', '>=', $fs)
            ->get();

        $demoted = 0;
        foreach ($finalizedPayslips as $slip) {
            if (self::finalizedBatchSupportsPayslip($slip)) {
                continue;
            }
            Payslip::query()->whereKey($slip->id)->update([
                'status' => Payslip::STATUS_DRAFT,
                'finalized_at' => null,
                'finalized_by_user_id' => null,
            ]);
            $demoted++;

            $ps = $slip->pay_period_start->toDateString();
            $pe = $slip->pay_period_end->toDateString();
            PayrollPeriod::query()
                ->where('user_id', $userId)
                ->whereDate('from_date', $ps)
                ->whereDate('to_date', $pe)
                ->where('status', PayrollPeriod::STATUS_LOCKED)
                ->update(['status' => PayrollPeriod::STATUS_DRAFT]);
        }

        $lockedPeriods = PayrollPeriod::query()
            ->where('user_id', $userId)
            ->where('status', PayrollPeriod::STATUS_LOCKED)
            ->whereDate('from_date', '<=', $te)
            ->whereDate('to_date', '>=', $fs)
            ->get();

        $unlocked = 0;
        foreach ($lockedPeriods as $period) {
            if (Payslip::query()
                ->where('user_id', $userId)
                ->whereIn('status', Payslip::lockingStatuses())
                ->whereDate('pay_period_start', $period->from_date->toDateString())
                ->whereDate('pay_period_end', $period->to_date->toDateString())
                ->exists()) {
                continue;
            }
            if (self::finalizedBatchSupportsLockedPeriod($user, $period)) {
                continue;
            }
            $period->update(['status' => PayrollPeriod::STATUS_DRAFT]);
            $unlocked++;
        }

        if ($demoted > 0 || $unlocked > 0) {
            Log::notice('Payroll orphan lock reconcile (user window)', [
                'user_id' => $userId,
                'from' => $fs,
                'to' => $te,
                'demoted_payslips' => $demoted,
                'unlocked_periods' => $unlocked,
            ]);
        }
    }

    public static function reconcileForCalendarDate(Carbon $date, bool $force = false): void
    {
        if (! $force && ! self::isAutoReconcileEnabled()) {
            return;
        }

        $d = $date->toDateString();

        $slips = Payslip::query()
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereDate('pay_period_start', '<=', $d)
            ->whereDate('pay_period_end', '>=', $d)
            ->get();

        $demoted = 0;
        foreach ($slips as $slip) {
            if (self::finalizedBatchSupportsPayslip($slip)) {
                continue;
            }
            Payslip::query()->whereKey($slip->id)->update([
                'status' => Payslip::STATUS_DRAFT,
                'finalized_at' => null,
                'finalized_by_user_id' => null,
            ]);
            $demoted++;

            PayrollPeriod::query()
                ->where('user_id', $slip->user_id)
                ->whereDate('from_date', $slip->pay_period_start->toDateString())
                ->whereDate('to_date', $slip->pay_period_end->toDateString())
                ->where('status', PayrollPeriod::STATUS_LOCKED)
                ->update(['status' => PayrollPeriod::STATUS_DRAFT]);
        }

        if ($demoted > 0) {
            Log::notice('Payroll orphan lock reconcile (calendar date)', [
                'date' => $d,
                'demoted_payslips' => $demoted,
            ]);
        }

        $lockedPeriods = PayrollPeriod::query()
            ->where('status', PayrollPeriod::STATUS_LOCKED)
            ->whereDate('from_date', '<=', $d)
            ->whereDate('to_date', '>=', $d)
            ->get();

        $unlocked = 0;
        foreach ($lockedPeriods as $period) {
            $uid = (int) $period->user_id;
            $u = User::query()->find($uid);
            if (! $u) {
                continue;
            }
            if (Payslip::query()
                ->where('user_id', $uid)
                ->whereIn('status', Payslip::lockingStatuses())
                ->whereDate('pay_period_start', '<=', $d)
                ->whereDate('pay_period_end', '>=', $d)
                ->exists()) {
                continue;
            }
            if (self::finalizedBatchSupportsLockedPeriod($u, $period)) {
                continue;
            }
            $period->update(['status' => PayrollPeriod::STATUS_DRAFT]);
            $unlocked++;
        }

        if ($unlocked > 0) {
            Log::notice('Payroll orphan lock reconcile (calendar date, periods)', [
                'date' => $d,
                'unlocked_periods' => $unlocked,
            ]);
        }
    }

    /**
     * @param  list<int>|null  $employeeUserIds
     * @return array{payslips_demoted: int, payroll_periods_unlocked: int}
     */
    public static function adminForceUnlockPayWindow(
        int $companyId,
        Carbon $payPeriodStart,
        Carbon $payPeriodEnd,
        ?array $employeeUserIds,
        int $actorUserId
    ): array {
        $ps = $payPeriodStart->toDateString();
        $pe = $payPeriodEnd->toDateString();

        $userQuery = User::query()
            ->where('company_id', $companyId)
            ->payrollEmployees();

        if ($employeeUserIds !== null && $employeeUserIds !== []) {
            $userQuery->whereIn('id', $employeeUserIds);
        }

        $userIds = $userQuery->pluck('id')->all();
        if ($userIds === []) {
            Log::warning('Payroll pay window force-unlocked by admin (no employees in scope)', [
                'actor_user_id' => $actorUserId,
                'company_id' => $companyId,
                'pay_period_start' => $ps,
                'pay_period_end' => $pe,
            ]);

            return ['payslips_demoted' => 0, 'payroll_periods_unlocked' => 0];
        }

        $demoted = Payslip::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereDate('pay_period_start', $ps)
            ->whereDate('pay_period_end', $pe)
            ->update([
                'status' => Payslip::STATUS_DRAFT,
                'finalized_at' => null,
                'finalized_by_user_id' => null,
            ]);

        $unlocked = PayrollPeriod::query()
            ->whereIn('user_id', $userIds)
            ->where('status', PayrollPeriod::STATUS_LOCKED)
            ->whereDate('from_date', $ps)
            ->whereDate('to_date', $pe)
            ->update(['status' => PayrollPeriod::STATUS_DRAFT]);

        Log::warning('Payroll pay window force-unlocked by admin', [
            'actor_user_id' => $actorUserId,
            'company_id' => $companyId,
            'pay_period_start' => $ps,
            'pay_period_end' => $pe,
            'employee_filter_count' => $employeeUserIds !== null ? count($employeeUserIds) : null,
            'payslips_demoted' => $demoted,
            'payroll_periods_unlocked' => $unlocked,
        ]);

        return [
            'payslips_demoted' => (int) $demoted,
            'payroll_periods_unlocked' => (int) $unlocked,
        ];
    }

    /**
     * Reset failed batch rows so the UI/queue can treat them as retryable (draft), without deleting history.
     *
     * @param  list<int>|null  $employeeUserIds  When set, only rows with null employee_id or id in this list.
     */
    public static function resetFailedBatchRunsToDraft(
        int $companyId,
        string $payPeriodStart,
        string $payPeriodEnd,
        ?array $employeeUserIds = null
    ): int {
        $q = PayrollBatchRun::query()
            ->where('company_id', $companyId)
            ->whereDate('pay_period_start', $payPeriodStart)
            ->whereDate('pay_period_end', $payPeriodEnd)
            ->where('status', PayrollBatchRun::STATUS_FAILED);

        if ($employeeUserIds !== null && $employeeUserIds !== []) {
            $q->where(function (Builder $w) use ($employeeUserIds) {
                $w->whereNull('employee_id')->orWhereIn('employee_id', $employeeUserIds);
            });
        }

        $payload = [
            'status' => PayrollBatchRun::STATUS_DRAFT,
            'error_message' => null,
            'queued_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'finalized_at' => null,
            'finalized_by_user_id' => null,
        ];

        return (int) $q->update($payload);
    }

    /**
     * Admin repair: demote payslips, unlock periods, and reset matching failed batch runs to draft.
     *
     * @param  list<int>|null  $employeeUserIds
     * @return array{payslips_demoted: int, payroll_periods_unlocked: int, failed_batch_runs_reset_to_draft: int}
     */
    public static function forceUnlockPeriod(
        int $companyId,
        Carbon $payPeriodStart,
        Carbon $payPeriodEnd,
        ?array $employeeUserIds,
        int $actorUserId,
        bool $resetFailedBatchRuns = true
    ): array {
        $unlock = self::adminForceUnlockPayWindow(
            $companyId,
            $payPeriodStart,
            $payPeriodEnd,
            $employeeUserIds,
            $actorUserId
        );

        $reset = 0;
        if ($resetFailedBatchRuns) {
            $reset = self::resetFailedBatchRunsToDraft(
                $companyId,
                $payPeriodStart->toDateString(),
                $payPeriodEnd->toDateString(),
                $employeeUserIds
            );
        }

        if ($reset > 0) {
            Log::warning('Payroll forceUnlockPeriod: failed batch runs reset to draft', [
                'actor_user_id' => $actorUserId,
                'company_id' => $companyId,
                'pay_period_start' => $payPeriodStart->toDateString(),
                'pay_period_end' => $payPeriodEnd->toDateString(),
                'rows' => $reset,
            ]);
        }

        return [
            'payslips_demoted' => $unlock['payslips_demoted'],
            'payroll_periods_unlocked' => $unlock['payroll_periods_unlocked'],
            'failed_batch_runs_reset_to_draft' => $reset,
        ];
    }
}
