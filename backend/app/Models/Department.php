<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company_id',
        'branch_id',
        'division_id',
        'office_location',
        'description',
        'logo',
        'department_head_id',
        'hierarchy_mismatch',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $department): void {
            foreach (array_filter([$department->department_head_id, $department->getOriginal('department_head_id')]) as $employeeId) {
                try {
                    app(\App\Services\EmployeeLevelResolver::class)->syncCachedLevel((int) $employeeId, 'department_head_changed');
                } catch (\Throwable) {
                    // Employee level cache refresh should never block organization maintenance.
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'hierarchy_mismatch' => 'boolean',
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

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function departmentHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'department_head_id');
    }

    public function teamLeaders(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_team_leaders', 'department_id', 'employee_id')
            ->withTimestamps()
            ->orderBy('users.last_name')
            ->orderBy('users.first_name');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'department_id');
    }

    public function sectionsOrUnits(): HasMany
    {
        return $this->hasMany(SectionUnit::class, 'department_id');
    }

    public function benefitCatalogs(): HasMany
    {
        return $this->hasMany(BenefitCatalog::class, 'department_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'department_id');
    }
}
