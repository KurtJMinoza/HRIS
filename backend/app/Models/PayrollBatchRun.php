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

    protected $fillable = [
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
}
