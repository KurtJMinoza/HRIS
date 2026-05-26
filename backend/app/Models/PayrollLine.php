<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    public const TYPE_EARNING = 'earning';

    public const TYPE_DEDUCTION = 'deduction';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'payroll_employee_id',
        'payslip_id',
        'line_key',
        'component_code',
        'component_name',
        'description',
        'type',
        'category',
        'amount',
        'units',
        'schedule',
        'calculation_standard',
        'source_type',
        'source_id',
        'metadata',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'metadata' => 'array',
        ];
    }

    public function payrollEmployee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
