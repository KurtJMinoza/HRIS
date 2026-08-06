<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceCorrection extends Model
{
    public const SOURCE_ADMIN_MANUAL = 'admin_manual';

    protected $fillable = [
        'user_id',
        'date',
        'time_in',
        'time_out',
        'work_segments',
        'matched_schedule_option_id',
        'shift_match_mode',
        'remarks',
        'source_type',
        'is_manual',
        'manual_reason_code',
        'manual_remarks',
        'created_by_admin_id',
        'issue_kind',
        'approved',
        'approved_by',
        'approved_by_admin_id',
        'approved_at',
        'reversed_at',
        'reversed_by_admin_id',
        'reversal_reason',
        'pending_approval',
        'status',
        'final_approved_by',
        'reason_code',
        'manual_presence_reason',
        'filed_at',
        'filed_by',
        'filer_signature',
        'filer_signed_at',
        'rejected_at',
        'rejected_by',
        'rejection_note',
        'approval_stage',
        'first_approver_id',
        'first_approver_signature',
        'first_approved_at',
        'second_approver_id',
        'second_approver_signature',
        'second_approved_at',
        'is_incomplete_record',
        'attendance_logs_synced_at',
        'attendance_logs_synced_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'time_in' => 'datetime',
            'time_out' => 'datetime',
            'work_segments' => 'array',
            'is_manual' => 'boolean',
            'approved' => 'boolean',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
            'pending_approval' => 'boolean',
            'filed_at' => 'datetime',
            'filer_signed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'attendance_logs_synced_at' => 'datetime',
            'is_incomplete_record' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approver_id');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approver_id');
    }

    public function attendanceLogsSyncedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_logs_synced_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionAudit::class, 'attendance_correction_id')->orderBy('created_at');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionApproval::class, 'attendance_correction_id')->orderBy('acted_at')->orderBy('id');
    }

    public function manualRevisions(): HasMany
    {
        return $this->hasMany(ManualAttendanceRevision::class, 'attendance_record_id')->orderByDesc('changed_at');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function isAdminManual(): bool
    {
        return $this->source_type === self::SOURCE_ADMIN_MANUAL || (bool) $this->is_manual;
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function scopeAdminManual($query)
    {
        return $query->where(function ($q) {
            $q->where('source_type', self::SOURCE_ADMIN_MANUAL)->orWhere('is_manual', true);
        });
    }

    public function scopeActiveManual($query)
    {
        return $query->adminManual()->whereNull('reversed_at');
    }

    public function resolvedIssueKind(): string
    {
        $stored = is_string($this->issue_kind) ? trim($this->issue_kind) : '';
        if (in_array($stored, ['missing_in', 'missing_out', 'both'], true)) {
            return $stored;
        }

        $hasIn = $this->time_in !== null;
        $hasOut = $this->time_out !== null;
        if (! $hasIn && ! $hasOut) {
            return 'both';
        }
        if (! $hasIn) {
            return 'missing_in';
        }
        if (! $hasOut) {
            return 'missing_out';
        }

        return 'both';
    }

    public function hasRequiredTimesForFinalApproval(): bool
    {
        $issueKind = $this->resolvedIssueKind();

        if ($issueKind === 'missing_in' && $this->time_in === null) {
            return false;
        }
        if ($issueKind === 'missing_out' && $this->time_out === null) {
            return false;
        }
        if ($issueKind === 'both' && ($this->time_in === null || $this->time_out === null)) {
            return false;
        }
        if ($this->time_in !== null && $this->time_out !== null && $this->time_out->lessThanOrEqualTo($this->time_in)) {
            return false;
        }

        return true;
    }
}
