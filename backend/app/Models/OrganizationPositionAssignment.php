<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationPositionAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_level',
        'organization_unit_id',
        'position_type_id',
        'employee_id',
        'is_primary',
        'approval_priority',
        'effective_from',
        'effective_to',
        'is_active',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'approval_priority' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString());
            })
            ->where(function (Builder $q): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString());
            });
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function positionType(): BelongsTo
    {
        return $this->belongsTo(OrganizationPositionType::class, 'position_type_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function departmentScopes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrganizationLeadershipAssignmentScope::class, 'leadership_assignment_id');
    }

    public function activeDepartmentScopes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->departmentScopes()->where('is_active', true);
    }
}
