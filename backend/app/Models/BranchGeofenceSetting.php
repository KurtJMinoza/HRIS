<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchGeofenceSetting extends Model
{
    protected $fillable = [
        'branch_id',
        'allow_without_geofence',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'allow_without_geofence' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $settings) => \App\Services\GeofenceValidationService::forgetBranchCache((int) $settings->branch_id));
        static::deleted(fn (self $settings) => \App\Services\GeofenceValidationService::forgetBranchCache((int) $settings->branch_id));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
