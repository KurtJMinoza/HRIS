<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_EMAILED = 'emailed';

    /** Released to employee self-service (My Payslips); no email is sent. */
    public const STATUS_SENT_FINALIZED = 'sent_finalized';

    public const STATUS_VIEWED = 'viewed';

    /** Archived after a finalized payroll batch was voided; not shown in active lists. */
    public const STATUS_VOIDED = 'voided';

    /**
     * Statuses that lock the pay window and are treated as published (finalize / delivery pipeline).
     *
     * @return list<string>
     */
    public static function lockingStatuses(): array
    {
        return [
            self::STATUS_FINALIZED,
            self::STATUS_GENERATED,
            self::STATUS_EMAILED,
            self::STATUS_SENT_FINALIZED,
            self::STATUS_VIEWED,
        ];
    }

    protected $fillable = [
        'user_id',
        'payroll_period_id',
        'pay_cycle_id',
        'company_id',
        'branch_id',
        'department_id',
        'pay_period_start',
        'pay_period_end',
        'period_slot',
        'pay_date',
        'cycle_label',
        'gross_pay',
        'total_deductions',
        'net_pay',
        'ytd_gross',
        'ytd_deductions',
        'ytd_tax',
        'taxable_total_this_period',
        'non_taxable_total_this_period',
        'is_final_pay',
        'snapshot',
        'pdf_path',
        'status',
        'finalized_at',
        'finalized_by_user_id',
        'voided_at',
        'emailed_at',
        'delivered_at',
        'is_sent',
        'sent_at',
        'pdf_password_protected',
    ];

    protected function casts(): array
    {
        return [
            'pay_period_start' => 'date',
            'pay_period_end' => 'date',
            'pay_date' => 'date',
            'gross_pay' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'ytd_gross' => 'decimal:2',
            'ytd_deductions' => 'decimal:2',
            'ytd_tax' => 'decimal:2',
            'taxable_total_this_period' => 'decimal:2',
            'non_taxable_total_this_period' => 'decimal:2',
            'is_final_pay' => 'boolean',
            'snapshot' => 'array',
            'emailed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'is_sent' => 'boolean',
            'sent_at' => 'datetime',
            'pdf_password_protected' => 'boolean',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
            'period_slot' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function payCycle(): BelongsTo
    {
        return $this->belongsTo(PayCycle::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
