<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceCorrection extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'time_in',
        'time_out',
        'remarks',
        'issue_kind',
        'approved',
        'approved_by',
        'approved_at',
        'pending_approval',
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
            'approved' => 'boolean',
            'approved_at' => 'datetime',
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
}
