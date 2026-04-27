<?php

namespace App\Services;

use App\Models\PayrollBatchRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Batch wrapper around {@see PayslipService::sendPayslip()} (Finalize Payroll “Send payslips”).
 * Org scope is enforced inside {@see PayslipService::sendPayslip()}.
 */
final class PayslipDeliveryService
{
    public function __construct(
        private readonly PayslipService $payslipService,
    ) {}

    /**
     * @param  list<int>  $payslipIds
     * @return array{delivered: int, skipped: list<array{id: int, reason: string}>, errors: list<array{id: int, message: string}>}
     */
    public function deliverPayslips(
        array $payslipIds,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        User $actor
    ): array {
        $payslipIds = array_values(array_unique(array_filter(array_map('intval', $payslipIds), fn ($id) => $id > 0)));
        $out = [
            'delivered' => 0,
            'skipped' => [],
            'errors' => [],
        ];

        if ($payslipIds === []) {
            return $out;
        }

        foreach (array_slice($payslipIds, 0, 500) as $payslipId) {
            $pid = (int) $payslipId;
            $result = $this->payslipService->sendPayslip($pid, $actor, $companyId, $branchId, $departmentId);
            if (($result['ok'] ?? false) === true) {
                $out['delivered']++;

                continue;
            }
            $out['skipped'][] = ['id' => $pid, 'reason' => (string) ($result['reason'] ?? 'unknown')];
        }

        Log::info('Payslip delivery batch', [
            'actor_id' => (int) $actor->id,
            'delivered' => $out['delivered'],
            'skipped_count' => count($out['skipped']),
            'error_count' => count($out['errors']),
        ]);

        return $out;
    }

    /**
     * Deliver all payslips for one finalized payroll batch run.
     *
     * @return array{batch_id:int,targeted:int,delivered:int,skipped:list<array{id:int,reason:string}>,errors:list<array{id:int,message:string}>}
     */
    public function deliverFinalizedBatchPayslips(int $batchId, User $actor): array
    {
        $run = PayrollBatchRun::query()->findOrFail($batchId);
        if ((string) $run->status !== PayrollBatchRun::STATUS_FINALIZED) {
            throw new \RuntimeException('Bulk send is only available when the payroll batch status is finalized.');
        }

        $agg = $this->payslipService->aggregateForBatchRun($run);
        /** @var list<int> $payslipIds */
        $payslipIds = $agg['payslip_ids'] ?? [];
        if ($payslipIds === []) {
            throw new \RuntimeException('No payslips found for this finalized batch.');
        }

        $result = $this->deliverPayslips($payslipIds, null, null, null, $actor);

        return [
            'batch_id' => (int) $run->id,
            'targeted' => count($payslipIds),
            'delivered' => (int) ($result['delivered'] ?? 0),
            'skipped' => $result['skipped'] ?? [],
            'errors' => $result['errors'] ?? [],
        ];
    }
}
