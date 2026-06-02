<?php

namespace App\Services;

use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch wrapper around {@see PayslipService::sendPayslip()} (Finalize Payroll “Send payslips”).
 * Org scope is enforced inside {@see PayslipService::sendPayslip()}.
 */
final class PayslipDeliveryService
{
    public function __construct(
        private readonly PayslipService $payslipService,
        private readonly DataScopeService $dataScopeService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  list<int>  $payslipIds
     * @return array{delivered: int, notified: int, skipped: list<array{id: int, reason: string}>, errors: list<array{id: int, message: string}>}
     */
    public function deliverPayslips(
        array $payslipIds,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        User $actor,
        ?int $limit = 500,
    ): array {
        $payslipIds = array_values(array_unique(array_filter(array_map('intval', $payslipIds), fn ($id) => $id > 0)));
        $out = [
            'delivered' => 0,
            'notified' => 0,
            'skipped' => [],
            'errors' => [],
        ];

        if ($payslipIds === []) {
            return $out;
        }

        $targetIds = $limit === null ? $payslipIds : array_slice($payslipIds, 0, max(1, $limit));
        $result = $this->bulkMarkPayslipsDelivered($targetIds, $actor, $companyId, $branchId, $departmentId);
        $out['delivered'] = $result['delivered'];
        $out['skipped'] = $result['skipped'];
        $out['notified'] = $this->notifyReleasedPayslips($result['notify_payslips']);

        Log::info('Payslip delivery batch', [
            'actor_id' => (int) $actor->id,
            'delivered' => $out['delivered'],
            'notified' => $out['notified'],
            'skipped_count' => count($out['skipped']),
            'error_count' => count($out['errors']),
        ]);

        return $out;
    }

    /**
     * Deliver all payslips for one finalized payroll batch run.
     *
     * @return array{batch_id:int,targeted:int,delivered:int,notified:int,skipped:list<array{id:int,reason:string}>,errors:list<array{id:int,message:string}>}
     */
    public function deliverFinalizedBatchPayslips(int $batchId, User $actor): array
    {
        $run = PayrollBatchRun::query()->findOrFail($batchId);
        if ((string) $run->status !== PayrollBatchRun::STATUS_FINALIZED) {
            throw new \RuntimeException('Bulk send is only available when the payroll batch status is finalized.');
        }

        /** @var list<int> $payslipIds */
        $payslipIds = $this->payslipService->finalizedBatchPayslipIdsForDelivery($run);
        if ($payslipIds === []) {
            throw new \RuntimeException('No payslips found for this finalized batch.');
        }

        $result = $this->deliverPayslips($payslipIds, null, null, null, $actor, null);

        return [
            'batch_id' => (int) $run->id,
            'targeted' => count($payslipIds),
            'delivered' => (int) ($result['delivered'] ?? 0),
            'notified' => (int) ($result['notified'] ?? 0),
            'skipped' => $result['skipped'] ?? [],
            'errors' => $result['errors'] ?? [],
        ];
    }

    /**
     * Fast path for bulk delivery: one scoped query + chunked UPDATE.
     *
     * This intentionally does not generate/regenerate PDFs. "Send" in this module means
     * "release to My Payslips"; PDF generation remains lazy/on-demand and the single-send
     * method can still perform the slower PDF guarantee when needed.
     *
     * @param  list<int>  $payslipIds
     * @return array{delivered:int, notify_payslips:list<Payslip>, skipped:list<array{id:int,reason:string}>}
     */
    private function bulkMarkPayslipsDelivered(
        array $payslipIds,
        User $actor,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
    ): array {
        $now = now();
        $deliveredIds = [];
        $notifyPayslips = [];
        $publishedStatuses = Payslip::lockingStatuses();

        foreach (array_chunk($payslipIds, 500) as $chunk) {
            $query = Payslip::query()
                ->whereIn('id', $chunk)
                ->whereIn('status', $publishedStatuses)
                ->where('period_slot', 0)
                ->whereNull('voided_at')
                ->whereIn('user_id', $this->allowedUserSubquery($actor, $companyId, $branchId, $departmentId));

            $rows = (clone $query)->get([
                'id',
                'user_id',
                'company_id',
                'department_id',
                'pay_period_start',
                'pay_period_end',
                'cycle_label',
                'status',
                'delivered_at',
                'sent_at',
                'is_sent',
            ]);
            $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                continue;
            }

            foreach ($rows as $payslip) {
                if (! $payslip->is_sent || ! $payslip->sent_at || ! $payslip->delivered_at) {
                    $notifyPayslips[] = $payslip;
                }
            }

            DB::transaction(function () use ($ids, $now): void {
                Payslip::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'delivered_at' => $now,
                        'sent_at' => $now,
                        'is_sent' => true,
                        'status' => Payslip::STATUS_SENT_FINALIZED,
                        'updated_at' => $now,
                    ]);
            });

            array_push($deliveredIds, ...$ids);
        }

        $deliveredSet = array_flip($deliveredIds);
        $skipped = [];
        foreach ($payslipIds as $id) {
            if (! isset($deliveredSet[$id])) {
                $skipped[] = ['id' => (int) $id, 'reason' => 'not_finalized_or_out_of_scope'];
            }
        }

        return [
            'delivered' => count($deliveredIds),
            'notify_payslips' => $notifyPayslips,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  list<Payslip>  $payslips
     */
    private function notifyReleasedPayslips(array $payslips): int
    {
        $sent = 0;

        foreach ($payslips as $payslip) {
            try {
                $notification = $this->notificationService->notifyUser((int) $payslip->user_id, [
                    'type' => 'payslip.available',
                    'title' => 'Payslip available',
                    'message' => 'Your payslip for '.$this->periodLabel($payslip).' is now available in My Payslips.',
                    'module' => 'payslip',
                    'entity_id' => (int) $payslip->id,
                    'entity_type' => Payslip::class,
                    'action_url' => '/employee/payslips/view/'.((int) $payslip->id),
                    'company_id' => $payslip->company_id ? (int) $payslip->company_id : null,
                    'department_id' => $payslip->department_id ? (int) $payslip->department_id : null,
                    'priority' => 'normal',
                    'data' => [
                        'pay_period_start' => optional($payslip->pay_period_start)->toDateString(),
                        'pay_period_end' => optional($payslip->pay_period_end)->toDateString(),
                        'cycle_label' => $payslip->cycle_label,
                    ],
                ]);

                if ($notification) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning('Payslip delivery notification failed', [
                    'payslip_id' => (int) $payslip->id,
                    'user_id' => (int) $payslip->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    private function periodLabel(Payslip $payslip): string
    {
        $cycleLabel = trim((string) ($payslip->cycle_label ?? ''));
        if ($cycleLabel !== '') {
            return $cycleLabel;
        }

        $start = $payslip->pay_period_start;
        $end = $payslip->pay_period_end;
        if ($start && $end) {
            return $start->format('M j, Y').' - '.$end->format('M j, Y');
        }

        return 'the selected payroll period';
    }

    private function allowedUserSubquery(
        User $actor,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
    ): \Illuminate\Database\Eloquent\Builder {
        $allowedUserQuery = User::query()
            ->select('users.id')
            ->payrollEmployees()
            ->active();

        $this->dataScopeService->restrictEmployeeQuery($actor, $allowedUserQuery);

        if ($companyId) {
            $allowedUserQuery->where('users.company_id', $companyId);
        }
        if ($branchId) {
            $allowedUserQuery->where('users.branch_id', $branchId);
        }
        if ($departmentId) {
            $allowedUserQuery->where('users.department_id', $departmentId);
        }

        return $allowedUserQuery;
    }
}
