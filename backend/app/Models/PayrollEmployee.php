<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollEmployee extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    public const MODULE_STANDARD = 'standard';

    public const MODULE_EXECOM = 'execom';

    protected $fillable = [
        'payroll_module',
        'payslip_id',
        'payroll_batch_run_id',
        'user_id',
        'company_id',
        'pay_period_start',
        'pay_period_end',
        'status',
        'gross_pay',
        'total_deductions',
        'net_pay',
    ];

    protected function casts(): array
    {
        return [
            'pay_period_start' => 'date',
            'pay_period_end' => 'date',
            'gross_pay' => 'float',
            'total_deductions' => 'float',
            'net_pay' => 'float',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function batchRun(): BelongsTo
    {
        return $this->belongsTo(PayrollBatchRun::class, 'payroll_batch_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }
}
