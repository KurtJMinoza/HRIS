<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeGeofenceAssignment extends Model
{
    use SoftDeletes;

    public const ASSIGNMENT_TYPES = ['permanent', 'temporary', 'exemption'];

    public const VALIDATION_MODES = ['any_assigned_geofence', 'primary_geofence_only', 'no_geofence_required', 'location_only'];

    public const STATUSES = ['active', 'upcoming', 'expired', 'removed', 'replaced'];

    protected $fillable = [
        'employee_id',
        'geofence_id',
        'assignment_type',
        'validation_mode',
        'is_primary',
        'effective_start_date',
        'effective_end_date',
        'clock_in_applies',
        'clock_out_applies',
        'status',
        'reason',
        'approved_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'effective_start_date' => 'date',
            'effective_end_date' => 'date',
            'clock_in_applies' => 'boolean',
            'clock_out_applies' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(static function (self $assignment): void {
            \App\Services\EmployeeGeofenceResolver::forgetEmployeeCache((int) $assignment->employee_id);
        });
        static::deleted(static function (self $assignment): void {
            \App\Services\EmployeeGeofenceResolver::forgetEmployeeCache((int) $assignment->employee_id);
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(BranchGeofence::class, 'geofence_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEffectiveOn(\Carbon\CarbonInterface $date): bool
    {
        if ($this->status === 'removed' || $this->status === 'replaced') {
            return false;
        }

        $start = $this->effective_start_date?->startOfDay();
        $end = $this->effective_end_date?->endOfDay();

        if ($start && $date->lt($start)) {
            return false;
        }
        if ($end && $date->gt($end)) {
            return false;
        }

        return true;
    }

    public function appliesToAction(string $action): bool
    {
        $normalized = in_array($action, ['clock_in', 'in'], true) ? 'clock_in'
            : (in_array($action, ['clock_out', 'out'], true) ? 'clock_out' : $action);

        return match ($normalized) {
            'clock_in' => (bool) $this->clock_in_applies,
            'clock_out' => (bool) $this->clock_out_applies,
            default => true,
        };
    }
}
