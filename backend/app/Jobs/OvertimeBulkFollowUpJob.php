<?php

namespace App\Jobs;

use App\Models\Overtime;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OvertimeService;
use App\Services\ReportsCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OvertimeBulkFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  int[]  $overtimeIds
     */
    public function __construct(
        private readonly array $overtimeIds,
        private readonly int $actorId,
    ) {}

    public function handle(NotificationService $notificationService, OvertimeService $overtimeService): void
    {
        $actor = User::query()->find($this->actorId);
        if (! $actor) {
            return;
        }

        $records = Overtime::query()
            ->with('user')
            ->whereIn('id', array_values(array_unique(array_map('intval', $this->overtimeIds))))
            ->get();

        foreach ($records as $overtime) {
            $employee = $overtime->user;
            if (! $employee instanceof User) {
                continue;
            }

            $dateKey = $overtime->date?->toDateString();
            if ($dateKey !== null) {
                ReportsCacheService::invalidateAttendanceCache((int) $overtime->user_id, $dateKey);
                $overtimeService->syncActualClockOutToFiledOvertime($employee, $dateKey, $overtime->time_out, $actor);
                $this->clearAffectedDraftPayrollSnapshots($overtime, $dateKey);
            }

            if ($overtime->status === Overtime::STATUS_APPROVED) {
                $notificationService->notifyRequester(
                    $employee,
                    $overtime,
                    'overtime',
                    'overtime.final_approved',
                    'Overtime request approved',
                    'Your overtime request has been approved.',
                    '/employee/overtime?request_id='.$overtime->id,
                );
            }
        }
    }

    private function clearAffectedDraftPayrollSnapshots(Overtime $overtime, string $date): void
    {
        $drafts = Payslip::query()
            ->where('user_id', (int) $overtime->user_id)
            ->where('status', Payslip::STATUS_DRAFT)
            ->whereDate('pay_period_start', '<=', $date)
            ->whereDate('pay_period_end', '>=', $date)
            ->get(['id', 'company_id', 'pay_period_start', 'pay_period_end']);

        if ($drafts->isEmpty()) {
            return;
        }

        $draftIds = $drafts->pluck('id')->map(fn ($id) => (int) $id)->all();
        Payslip::query()->whereIn('id', $draftIds)->delete();

        foreach ($drafts as $draft) {
            PayrollBatchRun::query()
                ->where('status', PayrollBatchRun::STATUS_DRAFT)
                ->whereDate('pay_period_start', $draft->pay_period_start?->toDateString() ?? $date)
                ->whereDate('pay_period_end', $draft->pay_period_end?->toDateString() ?? $date)
                ->when($draft->company_id !== null, fn ($q) => $q->where('company_id', (int) $draft->company_id))
                ->update(['error_message' => 'Draft needs recompute: overtime request '.$overtime->id.' was approved.']);
        }

        Log::info('payroll_draft_cache_cleared_for_overtime_bulk', [
            'overtime_id' => (int) $overtime->id,
            'employee_id' => (int) $overtime->user_id,
            'date' => $date,
            'deleted_draft_payslip_ids' => $draftIds,
        ]);
    }
}
