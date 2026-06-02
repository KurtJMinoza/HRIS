<?php

namespace App\Models;

use App\Services\EmployeeLevelResolver;
use App\Services\HolidayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeOrganizationAssignment extends Model
{
    use HasFactory;

    public const TYPE_PRIMARY = 'primary';

    public const TYPE_SHARED = 'shared';

    public const TYPE_TEMPORARY = 'temporary';

    public const TYPE_ACTING = 'acting';

    protected $fillable = [
        'employee_id',
        'organization_unit_id',
        'assignment_type',
        'company_id',
        'branch_id',
        'division_id',
        'department_id',
        'section_unit_id',
        'is_primary',
        'immediate_leader_id',
        'effective_from',
        'effective_to',
        'is_active',
        'remarks',
    ];

    protected static function booted(): void
    {
        $flush = static function (self $assignment): void {
            try {
                app(HolidayService::class)->flushRuntimeCaches();
            } catch (\Throwable) {
                // Cache invalidation should never block assignment maintenance.
            }
            try {
                app(EmployeeLevelResolver::class)->syncCachedLevel((int) $assignment->employee_id, 'employee_organization_assignment_changed');
            } catch (\Throwable) {
                // Employee level cache refresh should never block assignment maintenance.
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function immediateLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'immediate_leader_id');
    }
}
