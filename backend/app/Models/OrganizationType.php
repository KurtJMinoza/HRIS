<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'level_order',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level_order' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function units(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class);
    }
}
