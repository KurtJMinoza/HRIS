<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceGlobalSetting extends Model
{
    protected $fillable = [
        'geofence_module_enabled',
        'attendance_without_geofence_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'geofence_module_enabled' => 'boolean',
            'attendance_without_geofence_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $flush = static fn () => \App\Services\GeofenceValidationService::forgetGlobalCache();
        static::saved($flush);
        static::deleted($flush);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
