<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationPositionType extends Model
{
    use HasFactory;

    public const LEVELS = [
        'company',
        'branch',
        'division',
        'department',
        'section_unit',
    ];

    protected $fillable = [
        'organization_level',
        'position_name',
        'approval_priority',
        'can_approve',
        'is_final_approver',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'approval_priority' => 'integer',
            'can_approve' => 'boolean',
            'is_final_approver' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrganizationPositionAssignment::class, 'position_type_id');
    }
}
