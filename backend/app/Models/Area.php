<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'area_name',
        'area_code',
        'area_manager_employee_id',
        'description',
        'status',
        'effective_from',
        'effective_to',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $area): void {
            foreach (array_filter([$area->area_manager_employee_id, $area->getOriginal('area_manager_employee_id')]) as $employeeId) {
                try {
                    app(\App\Services\EmployeeLevelResolver::class)->syncCachedLevel((int) $employeeId, 'area_head_changed');
                } catch (\Throwable) {
                    // Employee level cache refresh should never block organization maintenance.
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function areaManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'area_manager_employee_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
