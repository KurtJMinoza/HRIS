<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefundRequest extends Model
{
    /**
     * Internal direction taxonomy (do not label everything a "refund"):
     * underpayment → employee recovery; overpayment → payroll recovery;
     * payroll_adjustment → neutral correction.
     */
    public const DIRECTION_UNDERPAYMENT = 'underpayment';

    public const DIRECTION_OVERPAYMENT = 'overpayment';

    public const DIRECTION_ADJUSTMENT = 'payroll_adjustment';

    public const CATEGORY_ATTENDANCE = 'attendance';

    public const CATEGORY_OVERTIME = 'overtime';

    public const CATEGORY_HOLIDAY = 'holiday';

    public const CATEGORY_LEAVE = 'leave';

    public const CATEGORY_SCHEDULE = 'schedule';

    public const CATEGORY_PAYROLL_COMPUTATION = 'payroll_computation';

    public const CATEGORY_OTHER = 'other';

    // Refund reason slugs — mirrored in frontend/src/lib/refundConstants.js
    public const REASON_MISSING_TIME_IN = 'missing_time_in';

    public const REASON_MISSING_TIME_OUT = 'missing_time_out';

    public const REASON_MISSING_ATTENDANCE = 'missing_attendance';

    public const REASON_INCORRECT_LATE_DEDUCTION = 'incorrect_late_deduction';

    public const REASON_INCORRECT_UNDERTIME_DEDUCTION = 'incorrect_undertime_deduction';

    public const REASON_MISSING_OVERTIME = 'missing_overtime';

    public const REASON_INCORRECT_OVERTIME_PAY = 'incorrect_overtime_pay';

    public const REASON_MISSING_HOLIDAY_PAY = 'missing_holiday_pay';

    public const REASON_INCORRECT_HOLIDAY_PAY = 'incorrect_holiday_pay';

    public const REASON_MISSING_REST_DAY_PAY = 'missing_rest_day_pay';

    public const REASON_INCORRECT_REST_DAY_PREMIUM = 'incorrect_rest_day_premium';

    public const REASON_MISSING_NIGHT_DIFFERENTIAL = 'missing_night_differential';

    public const REASON_INCORRECT_LEAVE_PAY = 'incorrect_leave_pay';

    public const REASON_INCORRECT_LEAVE_DEDUCTION = 'incorrect_leave_deduction';

    public const REASON_SCHEDULE_ERROR = 'schedule_error';

    public const REASON_PAYROLL_COMPUTATION_ERROR = 'payroll_computation_error';

    public const REASON_OTHER = 'other';

    /** Refund reasons that land in the payroll report Basic Pay column (Excel/PDF). */
    public const BASIC_PAY_REPORT_REASONS = [
        self::REASON_MISSING_TIME_IN,
        self::REASON_MISSING_TIME_OUT,
        self::REASON_MISSING_ATTENDANCE,
        self::REASON_INCORRECT_LATE_DEDUCTION,
        self::REASON_INCORRECT_UNDERTIME_DEDUCTION,
        self::REASON_MISSING_REST_DAY_PAY,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PAYROLL_REVIEW = 'payroll_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_QUEUED_FOR_PAYROLL = 'queued_for_payroll';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_VOIDED = 'voided';

    /** Statuses that still allow edits by the creator. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_SUBMITTED];

    /** Approved refunds (and legacy queued rows) waiting for the next eligible payroll run. */
    public const PAYROLL_PENDING_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_QUEUED_FOR_PAYROLL,
    ];

    protected $fillable = [
        'refund_number',
        'employee_id',
        'company_id',
        'branch_id',
        'department_id',
        'direction',
        'category',
        'reason',
        'affected_date',
        'affected_date_to',
        'cutoff_start_date',
        'cutoff_end_date',
        'original_payroll_batch_run_id',
        'correction_payload',
        'calculation',
        'original_amount',
        'corrected_amount',
        'refund_amount',
        'reason_notes',
        'status',
        'created_by',
        'submitted_at',
        'submitted_by',
        'review_started_at',
        'reviewed_by',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'queued_at',
        'queued_by',
        'processed_batch_run_id',
        'processed_at',
        'processed_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'affected_date' => 'date',
            'affected_date_to' => 'date',
            'cutoff_start_date' => 'date',
            'cutoff_end_date' => 'date',
            'correction_payload' => 'array',
            'calculation' => 'array',
            'original_amount' => 'decimal:2',
            'corrected_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'queued_at' => 'datetime',
            'processed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public static function reasonOptions(): array
    {
        return [
            ['value' => self::REASON_MISSING_TIME_IN, 'category' => self::CATEGORY_ATTENDANCE, 'label' => 'Missing Time In'],
            ['value' => self::REASON_MISSING_TIME_OUT, 'category' => self::CATEGORY_ATTENDANCE, 'label' => 'Missing Time Out'],
            ['value' => self::REASON_MISSING_ATTENDANCE, 'category' => self::CATEGORY_ATTENDANCE, 'label' => 'Missing Attendance'],
            ['value' => self::REASON_INCORRECT_LATE_DEDUCTION, 'category' => self::CATEGORY_ATTENDANCE, 'label' => 'Incorrect Late Deduction'],
            ['value' => self::REASON_INCORRECT_UNDERTIME_DEDUCTION, 'category' => self::CATEGORY_ATTENDANCE, 'label' => 'Incorrect Undertime Deduction'],
            ['value' => self::REASON_MISSING_OVERTIME, 'category' => self::CATEGORY_OVERTIME, 'label' => 'Missing Overtime'],
            ['value' => self::REASON_INCORRECT_OVERTIME_PAY, 'category' => self::CATEGORY_OVERTIME, 'label' => 'Incorrect Overtime Pay'],
            ['value' => self::REASON_MISSING_HOLIDAY_PAY, 'category' => self::CATEGORY_HOLIDAY, 'label' => 'Missing Holiday Pay'],
            ['value' => self::REASON_INCORRECT_HOLIDAY_PAY, 'category' => self::CATEGORY_HOLIDAY, 'label' => 'Incorrect Holiday Pay'],
            ['value' => self::REASON_MISSING_REST_DAY_PAY, 'category' => self::CATEGORY_ATTENDANCE, 'label' => 'Missing Rest-Day Pay'],
            ['value' => self::REASON_INCORRECT_REST_DAY_PREMIUM, 'category' => self::CATEGORY_ATTENDANCE, 'label' => 'Incorrect Rest-Day Premium'],
            ['value' => self::REASON_MISSING_NIGHT_DIFFERENTIAL, 'category' => self::CATEGORY_ATTENDANCE, 'label' => 'Missing Night Differential'],
            ['value' => self::REASON_INCORRECT_LEAVE_PAY, 'category' => self::CATEGORY_LEAVE, 'label' => 'Incorrect Leave Pay'],
            ['value' => self::REASON_INCORRECT_LEAVE_DEDUCTION, 'category' => self::CATEGORY_LEAVE, 'label' => 'Incorrect Leave Deduction'],
            ['value' => self::REASON_SCHEDULE_ERROR, 'category' => self::CATEGORY_SCHEDULE, 'label' => 'Schedule Error'],
            ['value' => self::REASON_PAYROLL_COMPUTATION_ERROR, 'category' => self::CATEGORY_PAYROLL_COMPUTATION, 'label' => 'Payroll Computation Error'],
            ['value' => self::REASON_OTHER, 'category' => self::CATEGORY_OTHER, 'label' => 'Other'],
        ];
    }

    public static function categoriesForReason(string $reason): array
    {
        foreach (self::reasonOptions() as $option) {
            if ($option['value'] === $reason) {
                return [$option['category']];
            }
        }

        return [self::CATEGORY_OTHER];
    }

    public static function generateRefundNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "RFD-{$year}-";
        $last = static::query()
            ->where('refund_number', 'like', $prefix.'%')
            ->orderByDesc('refund_number')
            ->value('refund_number');
        $next = $last ? ((int) substr((string) $last, -6)) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function originalBatchRun(): BelongsTo
    {
        return $this->belongsTo(PayrollBatchRun::class, 'original_payroll_batch_run_id');
    }

    public function processedBatchRun(): BelongsTo
    {
        return $this->belongsTo(PayrollBatchRun::class, 'processed_batch_run_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(RefundRequestAudit::class)->orderBy('id');
    }

    public function reasonLabel(): string
    {
        foreach (self::reasonOptions() as $option) {
            if ($option['value'] === $this->reason) {
                return $option['label'];
            }
        }

        return ucwords(str_replace('_', ' ', (string) $this->reason));
    }

    public function directionLabel(): string
    {
        return match ($this->direction) {
            self::DIRECTION_UNDERPAYMENT => 'Refund / Recovery',
            self::DIRECTION_OVERPAYMENT => 'Payroll Recovery',
            default => 'Payroll Adjustment',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Submitted for Review',
            self::STATUS_PAYROLL_REVIEW => 'Payroll Review',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_QUEUED_FOR_PAYROLL => 'Queued for Payroll',
            self::STATUS_PROCESSED => 'Processed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_VOIDED => 'Voided',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }
}
