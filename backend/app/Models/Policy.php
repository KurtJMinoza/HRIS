<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Policy extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'company_id',
        'branch_id',
        'effective_date',
        'status',
        'version',
        'version_label',
        'priority_order_json',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'priority_order_json' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function multipliers(): HasMany
    {
        return $this->hasMany(PolicyMultiplier::class);
    }

    public function ndSetting(): HasOne
    {
        return $this->hasOne(PolicyNdSetting::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
