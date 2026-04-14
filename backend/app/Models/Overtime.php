<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Overtime extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'date',
        'schedule_end',
        'time_out',
        'expected_end_time',
        'computed_minutes',
        'computed_hours',
        'ph_ot_rule',
        'ot_type',
        'reason',
        'attachment_path',
        'status',
        'approved_by',
        'approved_at',
        'remarks',
        'locked_at',
        'created_by',
        'updated_by',
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
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'schedule_end' => 'datetime:H:i:s',
            'time_out' => 'datetime:H:i:s',
            'expected_end_time' => 'datetime:H:i:s',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'pending_approval' => 'boolean',
            'filed_at' => 'datetime',
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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

    public function adjustments(): HasMany
    {
        return $this->hasMany(OvertimeAdjustment::class);
    }

    public function approvalAudits(): HasMany
    {
        return $this->hasMany(OvertimeApprovalAudit::class)->orderBy('created_at');
    }
}
