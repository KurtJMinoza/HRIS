<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'type',
        'start_date',
        'end_date',
        'undertime_time',
        'shift_end_time',
        'actual_clock_out_time',
        'undertime_minutes',
        'is_auto_generated',
        'half_type',
        'status',
        'notes',
        'document_path',
        'document_paths',
        'reviewed_at',
        'reviewed_by',
        'approval_stage',
        'pending_approval',
        'filed_at',
        'filed_by',
        'first_approver_id',
        'first_approved_at',
        'second_approver_id',
        'second_approved_at',
        'rejected_at',
        'rejected_by',
        'rejection_note',
        'leave_credits_charged',
        'leave_unpaid_credit_days',
        'rest_day_bypass',
        'rest_day_bypass_reason',
        'rest_day_bypass_by',
        'rest_day_bypass_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'undertime_time' => 'string',
            'shift_end_time' => 'string',
            'actual_clock_out_time' => 'string',
            'undertime_minutes' => 'integer',
            'is_auto_generated' => 'boolean',
            'half_type' => 'string',
            'reviewed_at' => 'datetime',
            'pending_approval' => 'boolean',
            'filed_at' => 'datetime',
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'document_paths' => 'array',
            'leave_credits_charged' => 'integer',
            'leave_unpaid_credit_days' => 'integer',
            'rest_day_bypass' => 'boolean',
            'rest_day_bypass_at' => 'datetime',
        ];
    }

    public function restDayBypassByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rest_day_bypass_by');
    }

    /**
     * All stored relative paths for supporting documents (legacy document_path + JSON list).
     *
     * @return array<int, string>
     */
    public function resolveDocumentPaths(): array
    {
        $paths = $this->document_paths;
        if (! is_array($paths)) {
            $paths = [];
        }
        $paths = array_values(array_filter($paths, static fn ($p) => is_string($p) && $p !== ''));

        if (! empty($this->document_path) && is_string($this->document_path)) {
            if (! in_array($this->document_path, $paths, true)) {
                array_unshift($paths, $this->document_path);
            }
        }

        return array_values(array_unique($paths));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approver_id');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approver_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function approvalAudits(): HasMany
    {
        return $this->hasMany(LeaveApprovalAudit::class)->orderBy('created_at');
    }
}
