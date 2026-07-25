<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchGeofence extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'type',
        'device_scope',
        'center_lat',
        'center_lng',
        'radius_meters',
        'polygon_geojson',
        'is_active',
        'status',
        'enforcement_mode',
        'priority',
        'accuracy_threshold_meters',
        'ownership_type',
        'owner_employee_id',
        'address',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'center_lat' => 'float',
            'center_lng' => 'float',
            'radius_meters' => 'integer',
            'polygon_geojson' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'accuracy_threshold_meters' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $flush = static function (self $geofence): void {
            \App\Services\GeofenceValidationService::forgetBranchCache((int) $geofence->branch_id);
            $ownerIds = array_values(array_unique(array_filter([
                $geofence->owner_employee_id !== null ? (int) $geofence->owner_employee_id : null,
                $geofence->wasChanged('owner_employee_id') && $geofence->getOriginal('owner_employee_id')
                    ? (int) $geofence->getOriginal('owner_employee_id')
                    : null,
            ])));
            foreach ($ownerIds as $ownerId) {
                \App\Services\EmployeeGeofenceResolver::forgetEmployeeCache($ownerId);
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isEmployeeSpecific(): bool
    {
        return ($this->ownership_type ?? 'shared') === 'employee_specific';
    }
}
