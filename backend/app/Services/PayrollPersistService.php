<?php

namespace App\Services;

use App\Models\PayCycle;
use App\Models\PayrollBreakdown;
use App\Models\PayrollPeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persists a computed payroll run (daily breakdowns, statutory rows, loan balance hooks).
 * Shared by {@see \App\Http\Controllers\Admin\PayrollController::compute} (save=true) and
 * {@see FinalizePayrollService} so finalize payroll and daily computation stay aligned with
 * {@see PayrollComputationService::computeEmployeePayroll}.
 */
class PayrollPersistService
{
    public function __construct(
        private readonly DeductionApplicationService $deductionApplicationService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
    ) {}

    /**
     * @param  array<string, mixed>|null  $cyclePreview  From {@see PayCycleService::buildCyclePreview}
     * @return PayrollPeriod|null Null when there is nothing to persist (e.g. zero gross).
     */
    public function persistComputedPayroll(
        User $user,
        Carbon $from,
        Carbon $to,
        array $computed,
        ?array $cyclePreview,
        ?PayCycle $cycle
    ): ?PayrollPeriod {
        $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $user->id, $from, $to);

        $totalPay = (float) ($computed['summary']['total_pay'] ?? 0);
        if ($totalPay <= 0) {
            return null;
        }

        $period = PayrollPeriod::create([
            'user_id' => $user->id,
            'pay_cycle_id' => $cycle?->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'pay_cycle_code' => $cyclePreview['code'] ?? $cycle?->code,
            'cycle_label' => $cyclePreview['cycle_label'] ?? null,
            'reference_date' => $cyclePreview['reference_date'] ?? $from->toDateString(),
            'cut_off_start_date' => $cyclePreview['cut_off_start_date'] ?? $from->toDateString(),
            'cut_off_end_date' => $cyclePreview['cut_off_end_date'] ?? $to->toDateString(),
            'pay_date' => $cyclePreview['pay_date'] ?? null,
            'pro_ration_type' => $cyclePreview['pro_ration_type'] ?? $cycle?->pro_ration_type,
            'daily_rate' => $computed['daily_rate'],
            'basic_salary_used' => $computed['basic_salary_used'] ?? 0,
            'total_pay' => $computed['summary']['total_pay'],
            'employee_statutory_total' => $computed['summary']['employee_statutory_total'] ?? 0,
            'employer_statutory_total' => $computed['summary']['employer_statutory_total'] ?? 0,
            'net_pay' => $computed['summary']['net_pay'] ?? $computed['summary']['total_pay'],
            'total_worked_minutes' => $computed['summary']['total_worked_minutes'],
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        foreach ($computed['days'] as $day) {
            if ($day['total_pay'] > 0 || $day['worked_minutes'] > 0) {
                $holiday = $day['holiday'] ?? null;
                $approvedOtHours = $day['approved_ot_hours'] ?? 0;
                $unapprovedOtHours = $day['unapproved_ot_hours'] ?? 0;
                PayrollBreakdown::create([
                    'payroll_period_id' => $period->id,
                    'date' => $day['date'],
                    'status' => $day['status'],
                    'is_rest_day' => $day['is_rest_day'],
                    'holiday_type' => $holiday ? ($holiday['type'] ?? null) : null,
                    'holiday_name' => $holiday ? ($holiday['name'] ?? null) : null,
                    'rule_code' => $day['conditions']['rule_code'] ?? null,
                    'worked_minutes' => $day['worked_minutes'],
                    'required_minutes' => $day['required_minutes'],
                    'regular_day_minutes' => $day['regular_day_minutes'],
                    'regular_night_minutes' => $day['regular_night_minutes'],
                    'ot_day_minutes' => $day['ot_day_minutes'],
                    'ot_night_minutes' => $day['ot_night_minutes'],
                    'late_deduction_minutes' => $day['late_deduction_minutes'],
                    'undertime_deduction_minutes' => $day['undertime_deduction_minutes'],
                    'regular_pay' => $day['regular_pay'] ?? 0,
                    'ot_pay' => $day['ot_pay'] ?? 0,
                    'nd_pay' => $day['nd_pay'] ?? 0,
                    'holiday_premium_pay' => $day['holiday_premium_pay'] ?? 0,
                    'approved_ot_minutes' => (int) round($approvedOtHours * 60),
                    'unapproved_ot_minutes' => (int) round($unapprovedOtHours * 60),
                    'conditions' => $day['conditions'],
                    'breakdown' => $day['breakdown'],
                    'total_pay' => $day['total_pay'],
                ]);
            }
        }

        $refDate = isset($cyclePreview['reference_date'])
            ? Carbon::parse((string) $cyclePreview['reference_date'])
            : $from->copy();
        $this->deductionApplicationService->applyLoanBalancesAfterSavedPayroll($user, $refDate, $period);

        $statutory = $computed['summary']['statutory_breakdown'] ?? null;
        if (is_array($statutory)) {
            // Backward compatibility: support both new and legacy statutory history schemas.
            $table = 'employee_statutory_contributions';
            $employeeKey = Schema::hasColumn($table, 'employee_id') ? 'employee_id' : 'user_id';
            $typeKey = Schema::hasColumn($table, 'type') ? 'type' : 'contribution_type';
            $hasPeriodMonthYear = Schema::hasColumn($table, 'period_month') && Schema::hasColumn($table, 'period_year');
            $hasPeriod = ! $hasPeriodMonthYear && Schema::hasColumn($table, 'period');
            $periodMonth = (int) $from->month;
            $periodYear = (int) $from->year;
            foreach (['sss', 'philhealth', 'pagibig'] as $key) {
                $row = $statutory[$key] ?? null;
                if (! is_array($row)) {
                    continue;
                }

                $lookup = [
                    $employeeKey => $user->id,
                    $typeKey => (string) ($row['type'] ?? strtoupper($key)),
                ];
                if ($hasPeriodMonthYear) {
                    $lookup['period_month'] = $periodMonth;
                    $lookup['period_year'] = $periodYear;
                } elseif ($hasPeriod) {
                    $lookup['period'] = sprintf('%04d-%02d', $periodYear, $periodMonth);
                }

                $updates = [];
                if (Schema::hasColumn($table, 'basic_salary_used')) {
                    $updates['basic_salary_used'] = (float) ($row['basic_salary_used'] ?? 0);
                } elseif (Schema::hasColumn($table, 'basic_salary')) {
                    $updates['basic_salary'] = (float) ($row['basic_salary_used'] ?? 0);
                }
                if (Schema::hasColumn($table, 'msc_used')) {
                    $updates['msc_used'] = isset($row['msc_used']) ? (float) $row['msc_used'] : null;
                }
                $bracketRange = $row['bracket_range'] ?? null;
                if ($bracketRange !== null && ! is_scalar($bracketRange)) {
                    $bracketRange = json_encode($bracketRange, JSON_UNESCAPED_UNICODE);
                }
                if (Schema::hasColumn($table, 'bracket_range')) {
                    $updates['bracket_range'] = $bracketRange;
                } elseif (Schema::hasColumn($table, 'salary_bracket')) {
                    $updates['salary_bracket'] = $bracketRange;
                }
                if (Schema::hasColumn($table, 'employer_amount')) {
                    $updates['employer_amount'] = (float) ($row['employer_amount'] ?? 0);
                } elseif (Schema::hasColumn($table, 'employer_share')) {
                    $updates['employer_share'] = (float) ($row['employer_amount'] ?? 0);
                }
                if (Schema::hasColumn($table, 'employee_amount')) {
                    $updates['employee_amount'] = (float) ($row['employee_amount'] ?? 0);
                } elseif (Schema::hasColumn($table, 'employee_share')) {
                    $updates['employee_share'] = (float) ($row['employee_amount'] ?? 0);
                }
                if (Schema::hasColumn($table, 'ec_amount')) {
                    $updates['ec_amount'] = (float) ($row['ec_amount'] ?? 0);
                }
                if (Schema::hasColumn($table, 'total_amount')) {
                    $updates['total_amount'] = (float) ($row['total_amount'] ?? 0);
                } elseif (Schema::hasColumn($table, 'total_contribution')) {
                    $updates['total_contribution'] = (float) ($row['total_amount'] ?? 0);
                }
                if (Schema::hasColumn($table, 'metadata')) {
                    $meta = $row['metadata'] ?? null;
                    // Raw query builder does not apply model JSON casts; structures must be JSON-encoded.
                    $updates['metadata'] = is_array($meta) || is_object($meta)
                        ? json_encode($meta, JSON_UNESCAPED_UNICODE)
                        : $meta;
                } elseif (Schema::hasColumn($table, 'computation_details')) {
                    $meta = $row['metadata'] ?? null;
                    $updates['computation_details'] = is_array($meta) || is_object($meta)
                        ? json_encode($meta, JSON_UNESCAPED_UNICODE)
                        : $meta;
                }

                if (Schema::hasColumn($table, 'updated_at')) {
                    $updates['updated_at'] = now();
                }

                DB::table($table)->updateOrInsert($lookup, $updates);
            }
        }

        return $period->fresh();
    }
}
