<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceValidationLog extends Model
{
    protected $fillable = [
        'employee_id',
        'user_id',
        'company_id',
        'clock_type',
        'branch_id',
        'attempted_branch_id',
        'attendance_log_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'matched_geofence_id',
        'is_inside',
        'distance_to_center',
        'distance_meters',
        'radius_meters',
        'geofence_type',
        'validation_status',
        'enforcement_mode',
        'failure_reason',
        'device_type',
        'method',
        'sampled_readings_count',
        'selected_best_accuracy',
        'accuracy_threshold_meters',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'float',
            'is_inside' => 'boolean',
            'distance_to_center' => 'float',
            'distance_meters' => 'float',
            'selected_best_accuracy' => 'float',
            'expires_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function attemptedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'attempted_branch_id');
    }

    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class);
    }

    public function matchedGeofence(): BelongsTo
    {
        return $this->belongsTo(BranchGeofence::class, 'matched_geofence_id');
    }
}
