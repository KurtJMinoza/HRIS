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
        'notes',
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
}
