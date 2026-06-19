<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceGeofenceEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'attendance_log_id',
        'geofence_validation_log_id',
        'employee_id',
        'company_id',
        'branch_id',
        'clock_type',
        'event_type',
        'latitude',
        'longitude',
        'accuracy_meters',
        'distance_meters',
        'geofence_status',
        'matched_geofence_id',
        'device_type',
        'browser',
        'platform',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'float',
            'distance_meters' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class);
    }

    public function geofenceValidationLog(): BelongsTo
    {
        return $this->belongsTo(GeofenceValidationLog::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function matchedGeofence(): BelongsTo
    {
        return $this->belongsTo(BranchGeofence::class, 'matched_geofence_id');
    }
}
