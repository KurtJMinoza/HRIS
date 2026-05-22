<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationUnit extends Model
{
    use HasFactory;

    public const ROUTING_ANY = 'any';

    public const ROUTING_FIRST_ASSIGNED = 'first_assigned';

    public const ROUTING_SPECIFIC_PER_EMPLOYEE = 'specific_per_employee';

    public const ROUTING_ROUND_ROBIN = 'round_robin';

    public const ROUTING_SEQUENTIAL = 'sequential';

    public const ROUTING_RULES = [
        self::ROUTING_ANY,
        self::ROUTING_FIRST_ASSIGNED,
        self::ROUTING_SPECIFIC_PER_EMPLOYEE,
        self::ROUTING_ROUND_ROBIN,
        self::ROUTING_SEQUENTIAL,
    ];

    protected $fillable = [
        'organization_type_id',
        'parent_id',
        'company_id',
        'name',
        'code',
        'description',
        'is_active',
        'approval_routing_rule',
        'sort_order',
        'legacy_source_type',
        'legacy_source_id',
        'hierarchy_mismatch',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'legacy_source_id' => 'integer',
            'hierarchy_mismatch' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(OrganizationType::class, 'organization_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leaders(): HasMany
    {
        return $this->hasMany(OrganizationUnitLeader::class)
            ->orderByDesc('is_primary')
            ->orderBy('approval_priority')
            ->orderBy('id');
    }

    public function activeLeaders(): HasMany
    {
        return $this->leaders()->where('is_active', true);
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeOrganizationAssignment::class);
    }

    public function activeEmployeeAssignments(): HasMany
    {
        return $this->employeeAssignments()->where('is_active', true);
    }

    public function positionAssignments(): HasMany
    {
        return $this->hasMany(OrganizationPositionAssignment::class)
            ->orderBy('approval_priority')
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }

    public function activePositionAssignments(): HasMany
    {
        return $this->positionAssignments()->active();
    }
}
