<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company_id',
        'address',
        'branch_manager_id',
        'default_pay_cycle_id',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branchManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'branch_manager_id');
    }

    public function defaultPayCycle(): BelongsTo
    {
        return $this->belongsTo(PayCycle::class, 'default_pay_cycle_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'branch_id');
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
            ->whereIn('users.role', User::ROSTER_ELIGIBLE_ROLES)
            ->where(function ($q) {
                $q->whereColumn('users.branch_id', 'branches.id')
                    ->orWhereIn('users.department_id', Department::query()->select('id')->whereColumn('departments.branch_id', 'branches.id'));
            })
            ->selectRaw('count(*)');

        return $query->addSelect(['employees_count' => $sub]);
    }
}
