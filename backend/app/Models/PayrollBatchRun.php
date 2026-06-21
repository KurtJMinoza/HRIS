<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollBatchRun extends Model
{
    /**
     * Draft payroll batch (generated, not locked). This is the new default state after "Generate Payslips".
     * Finalize transitions the run to {@see STATUS_FINALIZED}.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FINALIZED = 'finalized';

    /** Finalized batch voided/cancelled; snapshots preserved, not editable as draft. */
    public const STATUS_VOIDED = 'voided';

    public const MODULE_STANDARD = 'standard';

    public const MODULE_EXECOM = 'execom';

    protected $fillable = [
        'payroll_module',
        'batch_key',
        'company_id',
        'branch_id',
        'department_id',
        'employee_id',
        'pay_period_start',
        'pay_period_end',
        'pay_cycle_id',
        'payroll_period_id',
        'is_final_pay',
        'password_protect',
        'reference_date',
        'status',
        'total_gross',
        'total_deductions',
        'total_net',
        'employee_count',
        'total_employees',
        'processed_employees',
        'failed_employees',
        'error_message',
        'queued_at',
        'started_at',
        'completed_at',
        'finalized_by_user_id',
        'finalized_at',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'pay_period_start' => 'date',
            'pay_period_end' => 'date',
            'reference_date' => 'date',
            'is_final_pay' => 'boolean',
            'password_protect' => 'boolean',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'total_employees' => 'integer',
            'processed_employees' => 'integer',
            'failed_employees' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Stable identity for a payroll batch scope (module + org filters + pay window).
     */
    public static function buildScopeBatchKey(
        string $payrollModule,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $employeeId,
        \Carbon\Carbon|string $payPeriodStart,
        \Carbon\Carbon|string $payPeriodEnd,
        mixed $payCycleId = null,
    ): string {
        $start = $payPeriodStart instanceof \Carbon\Carbon
            ? $payPeriodStart->toDateString()
            : \Carbon\Carbon::parse((string) $payPeriodStart)->toDateString();
        $end = $payPeriodEnd instanceof \Carbon\Carbon
            ? $payPeriodEnd->toDateString()
            : \Carbon\Carbon::parse((string) $payPeriodEnd)->toDateString();

        return hash('sha256', implode('|', [
            strtolower(trim($payrollModule)),
            (string) ($companyId ?? 'x'),
            (string) ($branchId ?? 'x'),
            (string) ($departmentId ?? 'x'),
            (string) ($employeeId ?? 'x'),
            $start,
            $end,
            (string) ($payCycleId ?? 'x'),
        ]));
    }

    /**
     * Legacy finalize hash (pre-module batch keys). Kept for voided/finalized rows created before module-scoped keys.
     */
    public static function buildLegacyScopeBatchKey(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $employeeId,
        \Carbon\Carbon|string $payPeriodStart,
        \Carbon\Carbon|string $payPeriodEnd,
        mixed $payCycleId = null,
    ): string {
        $start = $payPeriodStart instanceof \Carbon\Carbon
            ? $payPeriodStart->toDateString()
            : \Carbon\Carbon::parse((string) $payPeriodStart)->toDateString();
        $end = $payPeriodEnd instanceof \Carbon\Carbon
            ? $payPeriodEnd->toDateString()
            : \Carbon\Carbon::parse((string) $payPeriodEnd)->toDateString();

        return hash('sha256', implode('|', [
            (string) ($companyId ?? 'x'),
            (string) ($branchId ?? 'x'),
            (string) ($departmentId ?? 'x'),
            (string) ($employeeId ?? 'x'),
            $start,
            $end,
            (string) ($payCycleId ?? 'x'),
        ]));
    }

    public static function findConflictingFinalizedBatch(
        string $payrollModule,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $employeeId,
        \Carbon\Carbon|string $payPeriodStart,
        \Carbon\Carbon|string $payPeriodEnd,
    ): ?self {
        $query = static::query()
            ->where('payroll_module', strtolower(trim($payrollModule)))
            ->where('status', self::STATUS_FINALIZED)
            ->whereDate('pay_period_start', $payPeriodStart instanceof \Carbon\Carbon ? $payPeriodStart->toDateString() : $payPeriodStart)
            ->whereDate('pay_period_end', $payPeriodEnd instanceof \Carbon\Carbon ? $payPeriodEnd->toDateString() : $payPeriodEnd);

        static::applyNullableScopeColumn($query, 'company_id', $companyId);
        static::applyNullableScopeColumn($query, 'branch_id', $branchId);
        static::applyNullableScopeColumn($query, 'department_id', $departmentId);
        static::applyNullableScopeColumn($query, 'employee_id', $employeeId);

        return $query->orderByDesc('id')->first();
    }

    private static function applyNullableScopeColumn(\Illuminate\Database\Eloquent\Builder $query, string $column, ?int $value): void
    {
        if ($value !== null && $value > 0) {
            $query->where($column, $value);
        } else {
            $query->whereNull($column);
        }
    }
}
