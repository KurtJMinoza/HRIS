<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SectionUnit extends Model
{
    use HasFactory;

    protected $table = 'sections_or_units';

    protected $fillable = [
        'name',
        'code',
        'company_id',
        'branch_id',
        'department_id',
        'division_id',
        'section_unit_head_id',
        'status',
        'description',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $sectionUnit): void {
            foreach (array_filter([$sectionUnit->section_unit_head_id, $sectionUnit->getOriginal('section_unit_head_id')]) as $employeeId) {
                try {
                    app(\App\Services\EmployeeLevelResolver::class)->syncCachedLevel((int) $employeeId, 'section_unit_head_changed');
                } catch (\Throwable) {
                    // Employee level cache refresh should never block organization maintenance.
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function sectionUnitHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'section_unit_head_id');
    }

    public function teamLeaders(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'section_unit_team_leaders', 'section_unit_id', 'employee_id')
            ->withTimestamps()
            ->orderBy('users.last_name')
            ->orderBy('users.first_name');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'section_unit_id');
    }
}
