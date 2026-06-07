<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company_id',
        'area_id',
        'address',
        'branch_latitude',
        'branch_longitude',
        'branch_address',
        'branch_city',
        'branch_province',
        'branch_postal_code',
        'branch_manager_id',
        'default_pay_cycle_id',
        'geofence_enabled',
        'geofence_enforcement_mode',
        'geofence_no_active_policy',
        'geofence_accuracy_policy',
        'geofence_accuracy_buffer_mode',
        'geofence_poor_accuracy_action',
        'geofence_default_accuracy_threshold_meters',
        'geofence_mobile_accuracy_threshold_meters',
        'geofence_desktop_accuracy_threshold_meters',
        'geofence_minimum_samples',
        'geofence_maximum_samples',
        'geofence_sample_timeout_seconds',
        'geofence_allow_cross_branch',
        'geofence_require_backend_validation',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $branch): void {
            if ($branch->wasChanged([
                'geofence_enabled',
                'geofence_enforcement_mode',
                'geofence_no_active_policy',
                'geofence_accuracy_policy',
                'geofence_accuracy_buffer_mode',
                'geofence_poor_accuracy_action',
                'geofence_default_accuracy_threshold_meters',
                'geofence_mobile_accuracy_threshold_meters',
                'geofence_desktop_accuracy_threshold_meters',
                'geofence_minimum_samples',
                'geofence_maximum_samples',
                'geofence_sample_timeout_seconds',
                'geofence_allow_cross_branch',
                'geofence_require_backend_validation',
                'branch_latitude',
                'branch_longitude',
                'branch_address',
                'branch_city',
                'branch_province',
                'branch_postal_code',
            ])) {
                \App\Services\GeofenceValidationService::forgetBranchCache((int) $branch->id);
            }

            foreach (array_filter([$branch->branch_manager_id, $branch->getOriginal('branch_manager_id')]) as $employeeId) {
                try {
                    app(\App\Services\EmployeeLevelResolver::class)->syncCachedLevel((int) $employeeId, 'branch_head_changed');
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

    public function branchManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'branch_manager_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function defaultPayCycle(): BelongsTo
    {
        return $this->belongsTo(PayCycle::class, 'default_pay_cycle_id');
    }

    protected function casts(): array
    {
        return [
            'geofence_enabled' => 'boolean',
            'branch_latitude' => 'float',
            'branch_longitude' => 'float',
            'geofence_default_accuracy_threshold_meters' => 'integer',
            'geofence_mobile_accuracy_threshold_meters' => 'integer',
            'geofence_desktop_accuracy_threshold_meters' => 'integer',
            'geofence_minimum_samples' => 'integer',
            'geofence_maximum_samples' => 'integer',
            'geofence_sample_timeout_seconds' => 'integer',
            'geofence_allow_cross_branch' => 'boolean',
            'geofence_require_backend_validation' => 'boolean',
        ];
    }

    public function geofences(): HasMany
    {
        return $this->hasMany(BranchGeofence::class, 'branch_id');
    }

    public function geofenceSettings(): HasOne
    {
        return $this->hasOne(BranchGeofenceSetting::class, 'branch_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'branch_id');
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class, 'branch_id');
    }

    public function sectionsOrUnits(): HasMany
    {
        return $this->hasMany(SectionUnit::class, 'branch_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'branch_id');
    }

    /**
     * Count employees assigned to this branch (direct via branch_id or via department under this branch).
     */
    public function scopeWithTotalEmployeesCount(Builder $query): Builder
    {
        $sub = User::query()
            ->visibleEmployees()
            ->where(function ($q) {
                $q->whereColumn('users.branch_id', 'branches.id')
                    ->orWhereIn('users.department_id', Department::query()->select('id')->whereColumn('departments.branch_id', 'branches.id'));
            })
            ->selectRaw('count(*)');

        return $query->addSelect(['employees_count' => $sub]);
    }
}
