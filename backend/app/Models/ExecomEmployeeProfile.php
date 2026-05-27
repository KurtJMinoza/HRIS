<?php

namespace App\Models;

use App\Support\PayrollCacheInvalidator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecomEmployeeProfile extends Model
{
    public const PAY_SCHEDULE_PER_PERIOD = 'per_period';

    /** Monthly fixed salary divided across semi-monthly payroll runs. */
    public const PAY_SCHEDULE_MONTHLY_SPLIT = 'monthly_split';

    protected $fillable = [
        'employee_id',
        'company_id',
        'branch_id',
        'department_id',
        'fixed_salary',
        'pay_schedule',
        'is_active',
        'effective_from',
        'effective_to',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'fixed_salary' => 'decimal:2',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
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

    public function scopeActiveForPeriod(Builder $query, ?CarbonInterface $periodStart, ?CarbonInterface $periodEnd): Builder
    {
        $start = $periodStart?->toDateString() ?? now()->toDateString();
        $end = $periodEnd?->toDateString() ?? $start;

        return $query
            ->where('is_active', true)
            ->where(function (Builder $dateQuery) use ($end): void {
                $dateQuery->whereNull('effective_from')->orWhereDate('effective_from', '<=', $end);
            })
            ->where(function (Builder $dateQuery) use ($start): void {
                $dateQuery->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start);
            });
    }

    protected static function booted(): void
    {
        static::saved(function (ExecomEmployeeProfile $profile): void {
            if ($profile->wasChanged(['employee_id', 'company_id', 'branch_id', 'department_id', 'is_active', 'effective_from', 'effective_to'])) {
                PayrollCacheInvalidator::clear('execom_profile_saved', [
                    'employee_id' => (int) $profile->employee_id,
                    'company_id' => $profile->company_id ? (int) $profile->company_id : null,
                    'effective_from' => $profile->effective_from?->toDateString(),
                    'effective_to' => $profile->effective_to?->toDateString(),
                ]);
            }
        });

        static::deleted(function (ExecomEmployeeProfile $profile): void {
            PayrollCacheInvalidator::clear('execom_profile_deleted', [
                'employee_id' => (int) $profile->employee_id,
                'company_id' => $profile->company_id ? (int) $profile->company_id : null,
                'effective_from' => $profile->effective_from?->toDateString(),
                'effective_to' => $profile->effective_to?->toDateString(),
            ]);
        });
    }
}
