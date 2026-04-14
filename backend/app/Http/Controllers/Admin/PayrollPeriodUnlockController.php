<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PayrollPeriodOrphanLockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emergency repair: demote finalized payslips and unlock payroll_period rows for a pay window.
 * Laravel admin role only. Prefer automatic reconcile via {@see PayrollPeriodOrphanLockService}
 * when batch runs are missing; use this when you need an explicit override.
 */
class PayrollPeriodUnlockController extends Controller
{
    public function unlockPayWindow(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u && $u->isAdmin(), 403, 'Only administrators can unlock a payroll period.');

        $v = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'pay_period_start' => ['required', 'date'],
            'pay_period_end' => ['required', 'date', 'after_or_equal:pay_period_start'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'reset_failed_batches' => ['nullable', 'boolean'],
            'confirm' => ['required', 'accepted'],
        ]);

        $companyId = (int) $v['company_id'];
        $employeeIds = isset($v['employee_ids']) ? array_values(array_unique(array_map('intval', $v['employee_ids']))) : null;

        if ($employeeIds !== null && $employeeIds !== []) {
            $foreign = User::query()
                ->whereIn('id', $employeeIds)
                ->where('company_id', '!=', $companyId)
                ->exists();
            abort_if($foreign, 422, 'Each employee_id must belong to the selected company.');
        }

        $resetFailed = (bool) ($v['reset_failed_batches'] ?? true);

        $stats = PayrollPeriodOrphanLockService::forceUnlockPeriod(
            $companyId,
            Carbon::parse((string) $v['pay_period_start'])->startOfDay(),
            Carbon::parse((string) $v['pay_period_end'])->startOfDay(),
            $employeeIds,
            (int) $u->id,
            $resetFailed
        );

        return response()->json([
            'ok' => true,
            'payslips_demoted_to_draft' => $stats['payslips_demoted'],
            'payroll_periods_unlocked' => $stats['payroll_periods_unlocked'],
            'failed_batch_runs_reset_to_draft' => $stats['failed_batch_runs_reset_to_draft'],
        ]);
    }
}
