<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\OvertimeAdjustment;

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

    public function adjustments(): HasMany
    {
        return $this->hasMany(OvertimeAdjustment::class);
    }
}

