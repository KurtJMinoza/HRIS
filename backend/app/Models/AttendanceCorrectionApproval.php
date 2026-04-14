<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrectionApproval extends Model
{
    protected $fillable = [
        'attendance_correction_id',
        'approver_id',
        'level',
        'status',
        'notes',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'acted_at' => 'datetime',
        ];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(AttendanceCorrection::class, 'attendance_correction_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
