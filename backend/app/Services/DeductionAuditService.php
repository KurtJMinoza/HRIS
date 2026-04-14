<?php

namespace App\Services;

use App\Models\DeductionAuditLog;
use App\Models\EmployeeDeduction;

class DeductionAuditService
{
    /**
     * @param  array<string, mixed>|null  $oldValue
     * @param  array<string, mixed>|null  $newValue
     * @param  array<string, mixed>|null  $context
     */
    public function log(
        EmployeeDeduction $deduction,
        string $action,
        ?int $actorUserId = null,
        ?float $amount = null,
        ?float $remainingBalanceAfter = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $notes = null,
        ?array $context = null,
    ): DeductionAuditLog {
        return DeductionAuditLog::query()->create([
            'employee_deduction_id' => (int) $deduction->id,
            'user_id' => (int) $deduction->user_id,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'amount' => $amount !== null ? round($amount, 2) : null,
            'remaining_balance_after' => $remainingBalanceAfter !== null ? round($remainingBalanceAfter, 2) : null,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'notes' => $notes,
            'context' => $context,
        ]);
    }
}
