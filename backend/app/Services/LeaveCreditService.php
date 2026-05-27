<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\LeaveScheduleSupport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Philippine HR paid leave credits (shared VL-style pool).
 *
 * Eligibility for paid leave / credit pool:
 *
 * 1. **Employment status = Regular**.
 * 2. **One full year of service** completed, measured from the employee's hire date
 *    while the employee is in **Regular** status.
 *
 * Until both apply: **available_credits = 0**; employees may still file leave, but credit-consuming
 * types are **unpaid** (no deduction from pool; payroll excludes pay for those days).
 *
 * **Probationary** employees: **0** credits (not eligible). **Regular** employees with **less than
 * one full year of service**: **0** credits (not eligible yet). Only **Regular + ≥1 year** receive
 * the annual pool.
 *
 * **Annual pool:** configurable via leave.annual_allocation (default 7). **January 1** full reset
 * for eligible employees; unused credits do not carry over (not cumulative).
 *
 * **Regularization / employment updates:** once the employee is Regular **and** has completed
 * one full year from the current Status Effective Date, the balance is normalized to the
 * annual allocation when below.
 */
class LeaveCreditService
{
    /** Keep aligned with {@see config('cache.profile_ttl_minutes')} (max 5m) to limit cache churn. */
    private const SUMMARY_CACHE_TTL_MINUTES = 5;

    private static function summaryCacheKey(int $userId): string
    {
        return "leave:balance:{$userId}:summary:v1";
    }

    public function forgetSummaryCacheForUser(int $userId): void
    {
        Cache::forget(self::summaryCacheKey($userId));
    }

    public static function annualAllocation(): int
    {
        return max(0, (int) config('leave.annual_allocation', 7));
    }

    /** Human-readable copy for profile / leave UI. */
    public static function annualRechargePolicyCopy(): string
    {
        return 'Recharges on January 1st every year (full reset; unused credits do not carry over).';
    }

    public static function lastAnnualRechargeDisplay(?\Carbon\CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return 'Recharged on '.$date->format('M j, Y');
    }

    /**
     * Leave types that consume the shared paid-leave credit balance (whole-day units).
     */
    public function consumesCredits(string $type): bool
    {
        return in_array(strtolower(trim($type)), [
            'vacation',
            'sick',
            'emergency',
            'other',
            'half_day',
        ], true);
    }

    /**
     * Paid annual pool: **Regular** status **and** one full year from hire date.
     * Others get 0 pool credits.
     */
    public function eligibleForPaidLeavePool(User $user): bool
    {
        if (! $this->isRegularEmployment($user)) {
            return false;
        }

        return $this->hasCompletedOneYearOfService($user);
    }

    /**
     * True when employment_status is Regular (post-regularization in typical workflows).
     */
    public function isRegularEmployment(User $user): bool
    {
        return EmploymentStatus::tryFromStored((string) ($user->employment_status ?? '')) === EmploymentStatus::Regular;
    }

    /**
     * Date from which "one year of service" is measured for leave-credit eligibility.
     * Uses hire date first so imported long-tenured employees receive the paid pool after
     * automatic six-month regularization.
     */
    public function leaveCreditsServiceAnchorDate(User $user): ?Carbon
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));

        if (! $this->isRegularEmployment($user)) {
            return null;
        }

        if ($user->hire_date) {
            return Carbon::parse($user->hire_date, $tz)->startOfDay();
        }

        if ($user->employment_status_effective_date) {
            return Carbon::parse($user->employment_status_effective_date, $tz)->startOfDay();
        }

        return null;
    }

    /**
     * One full calendar year has passed since the leave-credit anchor date.
     */
    public function hasCompletedOneYearOfService(User $user, ?Carbon $asOf = null): bool
    {
        $anchor = $this->leaveCreditsServiceAnchorDate($user);
        if ($anchor === null) {
            return false;
        }

        $asOf = $asOf ?? Carbon::now(config('attendance.timezone', config('app.timezone', 'Asia/Manila')))->startOfDay();
        $oneYearAfterAnchor = $anchor->copy()->addYear()->startOfDay();

        return $asOf->greaterThanOrEqualTo($oneYearAfterAnchor);
    }

    public function isProbationaryEmployment(User $user): bool
    {
        return EmploymentStatus::tryFromStored((string) ($user->employment_status ?? '')) === EmploymentStatus::Probationary;
    }

    public function billableCreditDays(LeaveRequest $leave): int
    {
        $user = User::query()->find($leave->user_id);
        if (! $user) {
            return $this->billableCreditDaysFromFields((string) $leave->type, $leave->start_date, $leave->end_date);
        }

        return $this->billableCreditDaysForUser($user, (string) $leave->type, $leave->start_date, $leave->end_date);
    }

    /**
     * Credit-consuming days from the employee schedule (excludes scheduled rest days in range).
     */
    public function billableCreditDaysForUser(User $user, string $type, $startDate, $endDate): int
    {
        $t = strtolower(trim($type));
        if ($t === 'undertime') {
            return 0;
        }
        if (! $this->consumesCredits($t)) {
            return 0;
        }
        $startStr = $startDate instanceof Carbon ? $startDate->toDateString() : (string) $startDate;
        $endStr = $endDate instanceof Carbon ? $endDate->toDateString() : (string) $endDate;
        if ($t === 'half_day') {
            return LeaveScheduleSupport::isWorkingDay($user, $startStr) ? 1 : 0;
        }

        return LeaveScheduleSupport::countWorkingDaysInclusive($user, $startStr, $endStr);
    }

    public function billableCreditDaysFromFields(string $type, $startDate, $endDate): int
    {
        $t = strtolower(trim($type));
        if ($t === 'undertime') {
            return 0;
        }
        if (! $this->consumesCredits($t)) {
            return 0;
        }
        if ($t === 'half_day') {
            return 1;
        }
        $start = $startDate instanceof Carbon ? $startDate->copy()->startOfDay() : Carbon::parse((string) $startDate)->startOfDay();
        $end = $endDate instanceof Carbon ? $endDate->copy()->startOfDay() : Carbon::parse((string) $endDate)->startOfDay();
        if ($end->lessThan($start)) {
            return 0;
        }

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * If the calendar year has rolled since the last annual recharge, reset balance (eligible → annual
     * allocation; ineligible → 0). Safe to call on login / profile load; uses row lock.
     *
     * @return bool True if a recharge was applied
     */
    public function ensureAnnualRechargeForUser(User $user): bool
    {
        $recharged = DB::transaction(function () use ($user) {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $recharged = $this->applyAnnualRechargeIfDueLocked($locked, null);
            $this->normalizeLeaveCreditsForEligibilityLocked($locked, null);
            $this->reconcileEligibleAnnualAllocationLocked($locked, null);

            return $recharged;
        });

        if ($recharged) {
            $this->forgetSummaryCacheForUser((int) $user->id);
        }

        return $recharged;
    }

    public function ensureAnnualRechargeForUserId(int $userId): bool
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return false;
        }

        return $this->ensureAnnualRechargeForUser($user);
    }

    /**
     * Scheduled job: recharge all users who are still on a prior year's balance.
     *
     * @return int Number of users recharged
     */
    public function rechargeAllUsersDueForNewYear(?User $actor = null): int
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $startOfYear = Carbon::now($tz)->startOfYear();
        $count = 0;

        User::query()->orderBy('id')->chunkById(200, function ($users) use ($actor, &$count) {
            foreach ($users as $user) {
                DB::transaction(function () use ($user, $actor, &$count) {
                    /** @var User $locked */
                    $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    if ($this->applyAnnualRechargeIfDueLocked($locked, $actor)) {
                        $count++;
                    }
                    $this->normalizeLeaveCreditsForEligibilityLocked($locked, $actor);
                    $this->reconcileEligibleAnnualAllocationLocked($locked, $actor);
                });
            }
        });

        return $count;
    }

    /**
     * @internal Must be called with $user already lockForUpdate
     */
    private function applyAnnualRechargeIfDueLocked(User $locked, ?User $actor): bool
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $startOfYear = Carbon::now($tz)->startOfYear()->startOfDay();
        $resetDate = $locked->leave_credits_reset_date;

        if ($resetDate !== null) {
            $last = Carbon::parse($resetDate)->startOfDay();
            if ($last->greaterThanOrEqualTo($startOfYear)) {
                return false;
            }
        }

        $allocation = self::annualAllocation();
        $prev = (int) $locked->leave_credits;
        $eligible = $this->eligibleForPaidLeavePool($locked);
        $newBalance = $eligible ? $allocation : 0;
        $locked->leave_credits = $newBalance;
        $locked->leave_credits_reset_date = $startOfYear->toDateString();
        $locked->save();

        LeaveCreditTransaction::create([
            'user_id' => $locked->id,
            'change_type' => LeaveCreditTransaction::TYPE_ANNUAL_RESET,
            'delta' => $newBalance - $prev,
            'balance_after' => $newBalance,
            'reason' => $eligible
                ? 'Annual recharge (January 1) — balance set to '.$allocation.' paid-leave credits'
                : 'Annual recharge (January 1) — no paid-leave pool (probationary or under one year of service)',
            'leave_request_id' => null,
            'actor_id' => $actor?->id,
            'leave_type_context' => null,
        ]);

        return true;
    }

    /**
     * Probationary / under one year: stored balance must not show a paid pool (correct stale HR data).
     *
     * @internal Called with row lock
     */
    private function normalizeLeaveCreditsForEligibilityLocked(User $locked, ?User $actor): void
    {
        if ($this->eligibleForPaidLeavePool($locked)) {
            return;
        }
        $current = (int) $locked->leave_credits;
        if ($current <= 0) {
            return;
        }
        $locked->leave_credits = 0;
        $locked->save();

        LeaveCreditTransaction::create([
            'user_id' => $locked->id,
            'change_type' => LeaveCreditTransaction::TYPE_ADJUSTMENT,
            'delta' => -$current,
            'balance_after' => 0,
            'reason' => 'Eligibility: paid leave pool not available (probationary, non-regular, or under one year of service).',
            'leave_request_id' => null,
            'actor_id' => $actor?->id,
            'leave_type_context' => 'eligibility_normalize',
        ]);
    }

    /**
     * Backfill the annual allocation when the employee is already eligible this year but is still
     * carrying a stale zero balance from an earlier ineligible/reset state.
     *
     * Important:
     * - Do not overwrite employees who already have credits.
     * - Do not overwrite employees who already had any leave-credit activity this year.
     * - Only repair the common stale case: eligible now, current-year reset date is present, and
     *   balance is still zero with no current-year transactions.
     *
     * @internal Called with row lock
     */
    private function reconcileEligibleAnnualAllocationLocked(User $locked, ?User $actor): void
    {
        if (! $this->eligibleForPaidLeavePool($locked)) {
            return;
        }

        $current = (int) $locked->leave_credits;
        if ($current > 0) {
            return;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $startOfYear = Carbon::now($tz)->startOfYear()->startOfDay();
        $resetDate = $locked->leave_credits_reset_date;
        if ($resetDate === null) {
            return;
        }

        $lastReset = Carbon::parse($resetDate, $tz)->startOfDay();
        if ($lastReset->lessThan($startOfYear)) {
            return;
        }

        $currentYearTransactions = LeaveCreditTransaction::query()
            ->where('user_id', $locked->id)
            ->where('created_at', '>=', $startOfYear->copy()->setTimezone('UTC'))
            ->get(['change_type', 'leave_type_context']);

        $hasMeaningfulCurrentYearTransactions = $currentYearTransactions->contains(function (LeaveCreditTransaction $tx) {
            $type = (string) ($tx->change_type ?? '');
            $context = (string) ($tx->leave_type_context ?? '');

            // Ignore system-generated rows that merely zeroed-out or reset the stale balance while
            // the employee was still considered ineligible. Those should not block the repair.
            if ($type === LeaveCreditTransaction::TYPE_ANNUAL_RESET) {
                return false;
            }
            if ($type === LeaveCreditTransaction::TYPE_ADJUSTMENT && $context === 'eligibility_normalize') {
                return false;
            }

            return true;
        });

        if ($hasMeaningfulCurrentYearTransactions) {
            return;
        }

        $allocation = self::annualAllocation();
        $locked->leave_credits = $allocation;
        $locked->save();

        LeaveCreditTransaction::create([
            'user_id' => $locked->id,
            'change_type' => LeaveCreditTransaction::TYPE_ADDITION,
            'delta' => $allocation,
            'balance_after' => $allocation,
            'reason' => 'Eligibility reconciliation: employee is Regular and has completed one full year of service; annual paid-leave pool restored.',
            'leave_request_id' => null,
            'actor_id' => $actor?->id,
            'leave_type_context' => 'eligibility_reconcile',
        ]);
    }

    /**
     * Contract renewal: optionally reset credits to annual allocation (policy). Default is carry-over (no-op).
     */
    public function applyContractRenewalCreditPolicy(User $employee, ?User $actor = null): void
    {
        if (! config('leave.reset_on_contract_renewal', false)) {
            return;
        }

        DB::transaction(function () use ($employee, $actor) {
            /** @var User $locked */
            $locked = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if (! $this->eligibleForPaidLeavePool($locked)) {
                return;
            }
            $allocation = self::annualAllocation();
            $prev = (int) $locked->leave_credits;
            if ($prev === $allocation) {
                return;
            }
            $locked->leave_credits = $allocation;
            $locked->save();

            LeaveCreditTransaction::create([
                'user_id' => $locked->id,
                'change_type' => LeaveCreditTransaction::TYPE_ADJUSTMENT,
                'delta' => $allocation - $prev,
                'balance_after' => $allocation,
                'reason' => 'Contract renewal: policy reset to annual leave credits',
                'leave_request_id' => null,
                'actor_id' => $actor?->id,
                'leave_type_context' => 'contract_renewal',
            ]);
        });
    }

    public function getAvailableCredits(int $userId): int
    {
        $this->ensureAnnualRechargeForUserId($userId);
        $user = User::query()->find($userId);
        if (! $user || ! $this->eligibleForPaidLeavePool($user)) {
            return 0;
        }

        return (int) ($user->leave_credits ?? 0);
    }

    /**
     * Sum billable credit days for pending leave (excludes a specific request id when editing flow).
     * Pending requests from employees without a paid pool do not reserve credits.
     */
    public function sumPendingBillableDays(int $userId, ?int $exceptLeaveRequestId = null): int
    {
        $user = User::query()
            ->select(['id', 'employment_status', 'employment_status_effective_date', 'leave_credits'])
            ->find($userId);
        if (! $user || ! $this->eligibleForPaidLeavePool($user)) {
            return 0;
        }

        $q = LeaveRequest::query()
            ->where('user_id', $userId)
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->select(['id', 'type', 'start_date', 'end_date']);

        if ($exceptLeaveRequestId !== null) {
            $q->where('id', '!=', $exceptLeaveRequestId);
        }

        return (int) $q->get()->sum(
            fn (LeaveRequest $l) => $this->billableCreditDaysForUser($user, (string) $l->type, $l->start_date, $l->end_date)
        );
    }

    public function getEffectiveAvailableForNewRequest(int $userId, int $newRequestDays, ?int $exceptLeaveRequestId = null): int
    {
        return $this->getAvailableCredits($userId) - $this->sumPendingBillableDays($userId, $exceptLeaveRequestId) - $newRequestDays;
    }

    /**
     * Preview paid vs unpaid split for a leave request (same rules as {@see deductForFinalApproval}),
     * using schedule-based billable days. Safe to call from the leave form (no credit deduction).
     *
     * @return array<string, mixed>
     */
    public function previewPaidLeaveForRequest(
        User $user,
        string $type,
        $startDate,
        $endDate,
        ?int $exceptLeaveRequestId = null
    ): array {
        $this->ensureAnnualRechargeForUser($user);
        $user->refresh();

        $billable = $this->billableCreditDaysForUser($user, $type, $startDate, $endDate);
        $consumes = $this->consumesCredits($type);

        $annual = self::annualAllocation();
        $eligible = $this->eligibleForPaidLeavePool($user);
        $remaining = $eligible ? (int) ($user->leave_credits ?? 0) : 0;
        $pendingReserved = $this->sumPendingBillableDays((int) $user->id, $exceptLeaveRequestId);
        $effectiveAvailable = max(0, $remaining - $pendingReserved);

        $paidDays = 0;
        $unpaidDays = 0;
        if (! $consumes || $billable <= 0) {
            $paidDays = 0;
            $unpaidDays = 0;
        } elseif (! $eligible) {
            $paidDays = 0;
            $unpaidDays = $billable;
        } else {
            $paidDays = min($billable, $effectiveAvailable);
            $unpaidDays = max(0, $billable - $paidDays);
        }

        $message = null;
        $messageDetail = null;
        if (! $consumes || $billable <= 0) {
            $message = 'This request does not use leave credits.';
            $messageDetail = 'Undertime and non-credit types are not paid from the annual leave pool.';
        } elseif (! $eligible) {
            $message = 'This leave will be unpaid (not eligible for paid leave credits).';
            $messageDetail = 'Paid leave credits apply only to Regular employees with one full year of service from the hire date.';
        } elseif ($paidDays >= $billable && $billable > 0) {
            $message = 'This leave will be paid using your leave credits.';
            $messageDetail =
                $billable === 1
                    ? '1 credit will be deducted when the request is approved.'
                    : "{$billable} credits will be deducted when the request is approved (one per paid working day).";
        } elseif ($paidDays > 0 && $unpaidDays > 0) {
            $message = 'Part of this leave will be paid; the rest will be unpaid.';
            $messageDetail =
                "{$paidDays} day(s) paid from credits; {$unpaidDays} day(s) unpaid (no credits left for those days).";
        } elseif ($unpaidDays > 0) {
            $message = 'This leave will be unpaid (no credits left).';
            $messageDetail = 'Your balance is 0 after pending requests, so no salary is paid for these days.';
        }

        return [
            'billable_days' => $billable,
            'paid_days' => $paidDays,
            'unpaid_days' => $unpaidDays,
            'consumes_credits' => $consumes,
            'eligible_for_paid_leave_pool' => $eligible,
            'remaining_credits' => $remaining,
            'pending_reserved_days' => $pendingReserved,
            'effective_available' => $effectiveAvailable,
            'annual_allocation' => $annual,
            'message' => $message,
            'message_detail' => $messageDetail,
        ];
    }

    /**
     * Validates credit pool for a new request. Does not block filing when credits are insufficient —
     * excess days are approved as unpaid (see deductForFinalApproval). Super-admin bypass is unused here.
     */
    public function assertSufficientForNewRequest(User $employee, string $type, $startDate, $endDate, bool $bypass = false): void
    {
        if ($bypass) {
            return;
        }
        $this->ensureAnnualRechargeForUser($employee);
        $employee->refresh();

        $days = $this->billableCreditDaysForUser($employee, $type, $startDate, $endDate);
        if ($days <= 0) {
            return;
        }
        if (! $this->eligibleForPaidLeavePool($employee)) {
            return;
        }
        // Insufficient pool no longer throws — UI warns; approval splits paid/unpaid.
    }

    /**
     * After HR approves regularization to Regular: grant full annual pool if employee already has
     * one year of service (otherwise they receive credits on the next January 1 annual reset
     * once they are eligible).
     */
    public function grantAnnualAllocationOnRegularizationIfEligible(User $employee, ?User $actor = null): void
    {
        DB::transaction(function () use ($employee, $actor) {
            /** @var User $locked */
            $locked = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->applyAnnualRechargeIfDueLocked($locked, $actor);
            $this->normalizeLeaveCreditsForEligibilityLocked($locked, $actor);
            $locked->refresh();

            if (! $this->eligibleForPaidLeavePool($locked)) {
                return;
            }

            $allocation = self::annualAllocation();
            $current = (int) $locked->leave_credits;
            if ($current >= $allocation) {
                return;
            }

            $prev = $current;
            $locked->leave_credits = $allocation;
            $locked->save();

            LeaveCreditTransaction::create([
                'user_id' => $locked->id,
                'change_type' => LeaveCreditTransaction::TYPE_ADDITION,
                'delta' => $allocation - $prev,
                'balance_after' => $allocation,
                'reason' => 'Regularization: eligible employee receives annual paid-leave pool after completing one year of service ('.$allocation.' credits).',
                'leave_request_id' => null,
                'actor_id' => $actor?->id,
                'leave_type_context' => 'regularization',
            ]);
        });
        $this->forgetSummaryCacheForUser((int) $employee->id);
    }

    public function initializeLeaveCreditsForRegularEmployeeIfEligible(User $employee, ?User $actor = null, string $context = 'import'): void
    {
        DB::transaction(function () use ($employee, $actor, $context): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if (! $this->eligibleForPaidLeavePool($locked)) {
                return;
            }

            if (Schema::hasColumn('users', 'leave_credits_initialized_at') && $locked->leave_credits_initialized_at !== null) {
                return;
            }

            $alreadyInitialized = LeaveCreditTransaction::query()
                ->where('user_id', (int) $locked->id)
                ->where('leave_type_context', 'auto_regularization_'.$context)
                ->exists();
            if ($alreadyInitialized) {
                if (Schema::hasColumn('users', 'leave_credits_initialized_at')) {
                    $locked->leave_credits_initialized_at = now();
                    $locked->save();
                }

                return;
            }

            $allocation = self::annualAllocation();
            $current = (int) $locked->leave_credits;
            if ($current >= $allocation) {
                if (Schema::hasColumn('users', 'leave_credits_initialized_at')) {
                    $locked->leave_credits_initialized_at = now();
                    $locked->save();
                }

                return;
            }

            $locked->leave_credits = $allocation;
            if (Schema::hasColumn('users', 'leave_credits_reset_date') && $locked->leave_credits_reset_date === null) {
                $locked->leave_credits_reset_date = Carbon::now(config('attendance.timezone', config('app.timezone', 'Asia/Manila')))->startOfYear()->toDateString();
            }
            if (Schema::hasColumn('users', 'leave_credits_initialized_at')) {
                $locked->leave_credits_initialized_at = now();
            }
            $locked->save();

            LeaveCreditTransaction::create([
                'user_id' => $locked->id,
                'change_type' => LeaveCreditTransaction::TYPE_ADDITION,
                'delta' => $allocation - $current,
                'balance_after' => $allocation,
                'reason' => 'Auto-regularization: eligible imported employee receives default annual paid-leave pool.',
                'leave_request_id' => null,
                'actor_id' => $actor?->id,
                'leave_type_context' => 'auto_regularization_'.$context,
            ]);
        });

        $this->forgetSummaryCacheForUser((int) $employee->id);
    }

    /**
     * Final approval: deduct credits for paid portion only; remainder is unpaid (no deduction, payroll excludes pay).
     *
     * `leave_credits_charged` always equals credits actually deducted from the balance (never more than on-hand).
     */
    public function deductForFinalApproval(LeaveRequest $leave, ?User $actor, bool $forceInsufficient = false): void
    {
        if ($leave->leave_credits_charged !== null) {
            return;
        }

        DB::transaction(function () use ($leave, $actor, $forceInsufficient) {
            $leave = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if ($leave->leave_credits_charged !== null) {
                return;
            }
            $days = $this->billableCreditDays($leave);
            if ($days <= 0) {
                return;
            }

            /** @var User $user */
            $user = User::query()->whereKey($leave->user_id)->lockForUpdate()->firstOrFail();
            $this->applyAnnualRechargeIfDueLocked($user, null);
            $this->normalizeLeaveCreditsForEligibilityLocked($user, null);
            $user->refresh();

            if (! $this->eligibleForPaidLeavePool($user)) {
                $leave->leave_credits_charged = 0;
                $leave->leave_unpaid_credit_days = $days;
                $leave->save();

                return;
            }

            $pendingOther = $this->sumPendingBillableDays((int) $leave->user_id, (int) $leave->id);
            $current = (int) $user->leave_credits;
            $available = max(0, $current - $pendingOther);

            // Intended paid working-day slots: normal path respects pending reservations; force path caps at on-hand balance.
            $intendedPaidSlots = $forceInsufficient
                ? min($days, $current)
                : min($days, $available);

            $deductFromBalance = min($current, $intendedPaidSlots);
            $unpaidDays = max(0, $days - $deductFromBalance);

            if ($deductFromBalance > 0) {
                $newBalance = $current - $deductFromBalance;
                $user->leave_credits = $newBalance;
                $user->save();

                LeaveCreditTransaction::create([
                    'user_id' => $user->id,
                    'change_type' => LeaveCreditTransaction::TYPE_DEDUCTION,
                    'delta' => -$deductFromBalance,
                    'balance_after' => $newBalance,
                    'reason' => 'Approved leave request #'.$leave->id.' (paid portion)',
                    'leave_request_id' => $leave->id,
                    'actor_id' => $actor?->id,
                    'leave_type_context' => (string) $leave->type,
                ]);
            }

            $leave->leave_credits_charged = $deductFromBalance;
            $leave->leave_unpaid_credit_days = $unpaidDays;
            $leave->save();
        });
        $this->forgetSummaryCacheForUser((int) $leave->user_id);
    }

    /**
     * Payroll: for a given calendar date within an approved leave, whether that day is paid (draws from pool).
     * Legacy rows without split columns are treated as fully paid for credit-consuming types.
     */
    /**
     * Alias for {@see dateIsPaidLeavePortion()} — use this name when checking payroll/attendance by date.
     */
    public function isPaidLeaveDay(User $user, LeaveRequest $leave, string $dateKey): bool
    {
        return $this->dateIsPaidLeavePortion($user, $leave, $dateKey);
    }

    public function dateIsPaidLeavePortion(User $user, LeaveRequest $leave, string $dateKey): bool
    {
        if ($leave->status !== LeaveRequest::STATUS_APPROVED) {
            return false;
        }
        if (! $this->consumesCredits((string) $leave->type)) {
            return false;
        }
        $billable = $this->billableCreditDays($leave);
        if ($billable <= 0) {
            return false;
        }

        $charged = $leave->leave_credits_charged;
        $unpaid = $leave->leave_unpaid_credit_days;

        if ($unpaid === null && $charged === null) {
            return true;
        }

        $paidSlots = (int) ($charged ?? 0);
        if ($paidSlots <= 0) {
            return false;
        }

        $ordered = LeaveScheduleSupport::listWorkingDateStringsInRangeOrdered(
            $user,
            $leave->start_date->toDateString(),
            $leave->end_date->toDateString()
        );
        $idx = array_search($dateKey, $ordered, true);
        if ($idx === false) {
            return false;
        }

        return $idx < $paidSlots;
    }

    /**
     * Alias: deduct credits (e.g. manual correction flows).
     */
    public function deductCredits(int $userId, int $daysUsed, string $leaveType, string $reason, ?User $actor = null): int
    {
        if ($daysUsed <= 0) {
            throw ValidationException::withMessages([
                'days' => ['Days used must be a positive integer.'],
            ]);
        }

        return DB::transaction(function () use ($userId, $daysUsed, $leaveType, $reason, $actor) {
            /** @var User $user */
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $this->applyAnnualRechargeIfDueLocked($user, null);
            $this->normalizeLeaveCreditsForEligibilityLocked($user, null);
            $user->refresh();
            if (! $this->eligibleForPaidLeavePool($user)) {
                throw ValidationException::withMessages([
                    'leave_credits' => ['Employee is not eligible for paid leave credits.'],
                ]);
            }
            $current = (int) $user->leave_credits;
            if ($current < $daysUsed) {
                throw ValidationException::withMessages([
                    'leave_credits' => ['Insufficient leave credits. Employee has '.$current.' remaining.'],
                ]);
            }
            $newBalance = $current - $daysUsed;
            $user->leave_credits = $newBalance;
            $user->save();

            LeaveCreditTransaction::create([
                'user_id' => $user->id,
                'change_type' => LeaveCreditTransaction::TYPE_DEDUCTION,
                'delta' => -$daysUsed,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'leave_request_id' => null,
                'actor_id' => $actor?->id,
                'leave_type_context' => $leaveType,
            ]);

            $this->forgetSummaryCacheForUser((int) $user->id);

            return $newBalance;
        });
    }

    public function addLeaveCredits(int $userId, int $daysAdded, string $reason, ?User $actor = null): int
    {
        return $this->addCredits($userId, $daysAdded, $reason, $actor);
    }

    public function addCredits(int $userId, int $daysAdded, string $reason, ?User $actor = null): int
    {
        if ($daysAdded <= 0) {
            throw ValidationException::withMessages([
                'days' => ['Days added must be a positive integer.'],
            ]);
        }

        return DB::transaction(function () use ($userId, $daysAdded, $reason, $actor) {
            /** @var User $user */
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $this->applyAnnualRechargeIfDueLocked($user, null);
            $this->normalizeLeaveCreditsForEligibilityLocked($user, null);
            $user->refresh();
            if (! $this->eligibleForPaidLeavePool($user)) {
                throw ValidationException::withMessages([
                    'leave_credits' => ['Employee is not eligible for paid leave credits; HR adjustment blocked.'],
                ]);
            }
            $newBalance = (int) $user->leave_credits + $daysAdded;
            $user->leave_credits = $newBalance;
            $user->save();

            LeaveCreditTransaction::create([
                'user_id' => $user->id,
                'change_type' => LeaveCreditTransaction::TYPE_ADDITION,
                'delta' => $daysAdded,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'leave_request_id' => null,
                'actor_id' => $actor?->id,
                'leave_type_context' => null,
            ]);

            $this->forgetSummaryCacheForUser((int) $user->id);

            return $newBalance;
        });
    }

    /**
     * HR manual adjustment (positive or negative delta).
     */
    public function adjustLeaveCredits(int $userId, int $signedDelta, string $reason, ?User $actor = null): int
    {
        if ($signedDelta === 0) {
            throw ValidationException::withMessages([
                'delta' => ['Adjustment cannot be zero.'],
            ]);
        }

        return DB::transaction(function () use ($userId, $signedDelta, $reason, $actor) {
            /** @var User $user */
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $this->applyAnnualRechargeIfDueLocked($user, null);
            $this->normalizeLeaveCreditsForEligibilityLocked($user, null);
            $user->refresh();
            if (! $this->eligibleForPaidLeavePool($user)) {
                throw ValidationException::withMessages([
                    'leave_credits' => ['Employee is not eligible for paid leave credits; HR adjustment blocked.'],
                ]);
            }
            $newBalance = max(0, (int) $user->leave_credits + $signedDelta);
            $user->leave_credits = $newBalance;
            $user->save();

            LeaveCreditTransaction::create([
                'user_id' => $user->id,
                'change_type' => LeaveCreditTransaction::TYPE_ADJUSTMENT,
                'delta' => $signedDelta,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'leave_request_id' => null,
                'actor_id' => $actor?->id,
                'leave_type_context' => null,
            ]);

            $this->forgetSummaryCacheForUser((int) $user->id);

            return $newBalance;
        });
    }

    /**
     * Force annual recharge for all active users (e.g. cron on Jan 1). Same rules as lazy recharge.
     *
     * @return int Number of users updated
     */
    public function resetAnnualCredits(?int $year = null, ?User $actor = null): int
    {
        return $this->rechargeAllUsersDueForNewYear($actor);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function historyForUser(int $userId, int $limit = 50): array
    {
        return LeaveCreditTransaction::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->with(['actor:id,name,first_name,middle_name,last_name,suffix'])
            ->get()
            ->map(fn (LeaveCreditTransaction $row) => [
                'id' => $row->id,
                'change_type' => $row->change_type,
                'delta' => $row->delta,
                'balance_after' => $row->balance_after,
                'reason' => $row->reason,
                'leave_type_context' => $row->leave_type_context,
                'leave_request_id' => $row->leave_request_id,
                'actor_name' => $row->actor?->display_name,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Shared API payload for auth, employee profile, and leave summary endpoints.
     *
     * @return array<string, mixed>
     */
    public function buildLeaveCreditsApiPayload(User $user): array
    {
        return $this->getSummary($user, ['include_pending_reserved_days' => true]);
    }

    /**
     * Lightweight leave-balance summary for profile cards.
     * Cached to avoid repeating expensive eligibility/pending calculations on every profile load.
     *
     * @return array<string, mixed>
     */
    public function getSummary(User $user, array $options = []): array
    {
        $userId = (int) $user->id;
        $includePendingReservedDays = (bool) ($options['include_pending_reserved_days'] ?? false);

        return Cache::remember(
            self::summaryCacheKey($userId),
            now()->addMinutes(self::SUMMARY_CACHE_TTL_MINUTES),
            function () use ($userId, $includePendingReservedDays): array {
                $baseUser = User::query()->find($userId);
                if ($baseUser instanceof User) {
                    app(EmployeeStatusService::class)->syncAutomaticEmploymentStatus($baseUser);
                }
                $this->ensureAnnualRechargeForUserId($userId);
                $user = User::query()
                    ->select([
                        'id',
                        'employment_status',
                        'employment_status_effective_date',
                        'hire_date',
                        'regularization_date',
                        'leave_credits',
                        'leave_credits_reset_date',
                    ])
                    ->find($userId);
                if (! $user) {
                    return [
                        'remaining' => 0,
                        'annual_allocation' => self::annualAllocation(),
                        'reset_date' => null,
                        'last_recharged_display' => null,
                        'recharge_policy' => self::annualRechargePolicyCopy(),
                        'pending_reserved_days' => 0,
                        'effective_available' => 0,
                        'eligible_for_paid_leave_pool' => false,
                        'is_regular_employment' => false,
                        'probationary' => false,
                        'has_one_year_of_service' => false,
                        'employment_status' => null,
                        'service_anchor_date' => null,
                        'regular_service_start_date' => null,
                        'display' => '0/7 - Not yet eligible (under 1 year regular service)',
                        'status_summary' => 'Not eligible: paid leave credits require Regular status and one full year of service',
                        'unpaid_leave_notice' => 'This leave will be unpaid because you are not yet eligible for paid leave credits.',
                        'warning' => 'This leave will be unpaid because you are not yet eligible for paid leave credits.',
                    ];
                }
                $annual = self::annualAllocation();
                $eligible = $this->eligibleForPaidLeavePool($user);
                $probationary = $this->isProbationaryEmployment($user);
                $regular = $this->isRegularEmployment($user);
                $oneYear = $this->hasCompletedOneYearOfService($user);
                $remaining = $eligible ? (int) ($user->leave_credits ?? 0) : 0;
                // Keep profile card hot-path lightweight by default: pending-reserved
                // calculation scans pending leave requests and is deferred unless explicitly requested.
                $pendingReserved = $includePendingReservedDays ? $this->sumPendingBillableDays((int) $user->id) : 0;
                $effectiveAvailable = max(0, $remaining - $pendingReserved);

                // Shown only when not eligible for the pool (eligible-but-exhausted uses `warning` instead).
                $unpaidLeaveNotice = ! $eligible
                    ? 'This leave will be unpaid because you are not yet eligible for paid leave credits.'
                    : null;

                // Profile / My Leave: primary line (fraction) + status_summary (detail).
                if ($eligible) {
                    $display = "{$remaining}/{$annual} credits (Eligible)";
                    $statusSummary = 'Eligible for paid leave credits (Regular + 1 year service)';
                } else {
                    // Single line for any ineligible employee (probationary, Regular <1 year, or non-Regular).
                    $display = "0/{$annual} - Not yet eligible (under 1 year regular service)";
                    if ($probationary) {
                        $statusSummary = 'Not yet eligible: probationary employees do not receive paid leave credits';
                    } elseif ($regular && ! $oneYear) {
                        $statusSummary = 'Complete 1 full year of regular service to unlock paid leave credits.';
                    } else {
                        $statusSummary = 'Not eligible: paid leave credits require Regular status and one full year of service';
                    }
                }

                $warning = null;
                if ($eligible && $effectiveAvailable <= 0 && $pendingReserved === 0) {
                    $warning = 'No paid leave credits left. New credit-consuming leave will be unpaid.';
                } elseif ($eligible && $effectiveAvailable <= 0) {
                    $warning = 'No paid leave credits left after pending requests. Additional days may be unpaid.';
                } elseif (! $eligible) {
                    $warning = $unpaidLeaveNotice;
                }

                return [
                    'remaining' => $remaining,
                    'annual_allocation' => $annual,
                    'reset_date' => $user->leave_credits_reset_date?->toDateString(),
                    'last_recharged_display' => self::lastAnnualRechargeDisplay($user->leave_credits_reset_date),
                    'recharge_policy' => self::annualRechargePolicyCopy(),
                    'pending_reserved_days' => $pendingReserved,
                    'effective_available' => $effectiveAvailable,
                    'eligible_for_paid_leave_pool' => $eligible,
                    'is_regular_employment' => $regular,
                    'probationary' => $probationary,
                    'has_one_year_of_service' => $this->hasCompletedOneYearOfService($user),
                    'employment_status' => $user->employment_status,
                    'service_anchor_date' => $this->leaveCreditsServiceAnchorDate($user)?->toDateString(),
                    'regular_service_start_date' => $this->leaveCreditsServiceAnchorDate($user)?->toDateString(),
                    'display' => $display,
                    'status_summary' => $statusSummary,
                    'unpaid_leave_notice' => $unpaidLeaveNotice,
                    'warning' => $warning,
                ];
            }
        );
    }
}
