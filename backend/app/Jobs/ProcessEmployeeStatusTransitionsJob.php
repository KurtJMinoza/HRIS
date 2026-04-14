<?php

namespace App\Jobs;

use App\Enums\EmploymentStatus;
use App\Models\ProbationMilestoneNotification;
use App\Models\User;
use App\Services\EmployeeStatusService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily job for employment status automation:
 * - Early path: regularize when head recommendation is approved by HR and required actions are complete.
 * - Auto path: regularize at configured probation month (default 6) when required actions are complete.
 * - Prior month probation: audit log for HR regularization queue.
 * - Contractual: auto-separated on contract end date; log expiring within 30 days.
 *
 * Automatic month threshold is configurable in employment status settings.
 */
class ProcessEmployeeStatusTransitionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly ?string $asOfDate = null
    ) {}

    public function handle(EmployeeStatusService $statusService): void
    {
        $tz = config('attendance.timezone', 'Asia/Manila');
        $asOfDate = $this->asOfDate
            ? Carbon::parse($this->asOfDate, $tz)
            : Carbon::now($tz);

        Log::info('Processing employee status transitions', [
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        $this->processProbationRegularizations($statusService, $asOfDate);
        $this->recordProbationMilestoneNotifications($statusService, $asOfDate);
        $this->processContractualAccounts($statusService, $asOfDate);
    }

    private function processProbationRegularizations(EmployeeStatusService $statusService, Carbon $asOfDate): void
    {
        $probationaryEmployees = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true)
            ->where('employment_status', EmploymentStatus::Probationary->value)
            ->whereNotNull('hire_date')
            ->get();

        $regularizedCount = 0;
        $skippedCount = 0;
        $errors = [];

        foreach ($probationaryEmployees as $employee) {
            try {
                $processed = $this->processProbationEmployee($employee, $statusService, $asOfDate);
                if ($processed) {
                    $regularizedCount++;
                } else {
                    $skippedCount++;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'user_id' => $employee->id,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to process employee status transition', [
                    'user_id' => $employee->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('Probation regularization pass completed', [
            'total_evaluated' => $probationaryEmployees->count(),
            'regularized' => $regularizedCount,
            'skipped' => $skippedCount,
            'errors' => count($errors),
        ]);
    }

    /**
     * Early approved-recommendation path and configured automatic path.
     */
    private function processProbationEmployee(
        User $employee,
        EmployeeStatusService $statusService,
        Carbon $asOfDate
    ): bool {
        $employee->refresh();

        if ($employee->employment_status !== EmploymentStatus::Probationary->value) {
            return false;
        }

        if (! $employee->is_active) {
            return false;
        }

        if ($statusService->isEligibleForThreeMonthRegularization($employee, $asOfDate)) {
            $statusService->regularizeEmployee(
                $employee,
                'system_automation',
                null,
                'Regularized after 3+ months with approved head recommendation and HR approval (automation).',
                $asOfDate
            );

            Log::info('Employee regularized (3-month approved recommendation)', [
                'user_id' => $employee->id,
                'hire_date' => $employee->hire_date->toDateString(),
            ]);

            return true;
        }

        if ($statusService->isEligibleForSixMonthRegularization($employee, $asOfDate)) {
            $statusService->regularizeEmployee(
                $employee,
                'system_automation',
                null,
                'Automatically regularized after reaching configured probation period with completed required actions.',
                $asOfDate
            );

            Log::info('Employee regularized (automatic configured probation period)', [
                'user_id' => $employee->id,
                'hire_date' => $employee->hire_date->toDateString(),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Persist audit rows + logs when 5- or 6-month hire-date milestones fall on the run date (regularization queue).
     */
    private function recordProbationMilestoneNotifications(EmployeeStatusService $statusService, Carbon $asOfDate): void
    {
        $asOfDate = $asOfDate->copy()->startOfDay();

        $employees = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true)
            ->where('employment_status', EmploymentStatus::Probationary->value)
            ->whereNotNull('hire_date')
            ->get();

        $five = 0;
        $six = 0;

        foreach ($employees as $employee) {
            $hire = Carbon::parse($employee->hire_date)->startOfDay();
            $settings = $statusService->getAutomationSettings();
            $fiveMonth = $hire->copy()->addMonths(max(0, $settings['auto_regularization_months'] - 1))->startOfDay();
            $sixMonth = $hire->copy()->addMonths($settings['auto_regularization_months'])->startOfDay();

            if ($fiveMonth->equalTo($asOfDate)) {
                ProbationMilestoneNotification::firstOrCreate(
                    [
                        'user_id' => $employee->id,
                        'milestone' => ProbationMilestoneNotification::MILESTONE_FIVE_MONTH,
                    ],
                    [
                        'milestone_date' => $fiveMonth->toDateString(),
                        'notified_at' => now(),
                    ]
                );
                $five++;
                Log::info('Probation five-month review date reached', [
                    'user_id' => $employee->id,
                    'hire_date' => $employee->hire_date->toDateString(),
                    'five_month_date' => $fiveMonth->toDateString(),
                ]);
            }

            if ($sixMonth->equalTo($asOfDate)) {
                ProbationMilestoneNotification::firstOrCreate(
                    [
                        'user_id' => $employee->id,
                        'milestone' => ProbationMilestoneNotification::MILESTONE_SIX_MONTH,
                    ],
                    [
                        'milestone_date' => $sixMonth->toDateString(),
                        'notified_at' => now(),
                    ]
                );
                $six++;
                Log::info('Probation six-month HR decision date reached', [
                    'user_id' => $employee->id,
                    'hire_date' => $employee->hire_date->toDateString(),
                    'six_month_date' => $sixMonth->toDateString(),
                ]);
            }
        }

        if ($five > 0 || $six > 0) {
            Log::info('Probation milestone notifications recorded', [
                'five_month_count' => $five,
                'six_month_count' => $six,
                'date' => $asOfDate->toDateString(),
            ]);
        }
    }

    private function processContractualAccounts(EmployeeStatusService $statusService, Carbon $asOfDate): void
    {
        $asOf = $asOfDate->copy()->startOfDay();

        $users = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true)
            ->whereIn('employment_status', [
                EmploymentStatus::Contractual->value,
                EmploymentStatus::ProjectBased->value,
            ])
            ->whereNotNull('contract_end_date')
            ->get();

        foreach ($users as $user) {
            $end = Carbon::parse($user->contract_end_date)->startOfDay();
            $daysToEnd = $asOf->diffInDays($end, false);

            if ($daysToEnd >= 0 && $daysToEnd <= 30) {
                Log::info('Contractual employment expiring soon', [
                    'user_id' => $user->id,
                    'contract_end_date' => $end->toDateString(),
                    'days_remaining' => $daysToEnd,
                ]);
            }

            if ($asOf->greaterThan($end)) {
                $statusService->changeStatus(
                    $user,
                    EmploymentStatus::Separated,
                    'system_automation',
                    null,
                    'Contract end date passed; status set to Separated. Renew contract in Employment tab if applicable.',
                    $asOf
                );

                Log::info('Contractual employee separated after contract end', [
                    'user_id' => $user->id,
                    'contract_end_date' => $end->toDateString(),
                ]);
            }
        }
    }
}
