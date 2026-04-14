<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitCatalog extends Model
{
    public const TYPE_HEALTH_INSURANCE = 'health_insurance';

    public const TYPE_RETIREMENT_PLAN = 'retirement_plan';

    public const TYPE_LEAVE_BENEFITS = 'leave_benefits';

    public const TYPE_ALLOWANCE = 'allowance';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_HEALTH_INSURANCE,
        self::TYPE_RETIREMENT_PLAN,
        self::TYPE_LEAVE_BENEFITS,
        self::TYPE_ALLOWANCE,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'department_id',
        'type',
        'name',
        'metadata',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employeeBenefits(): HasMany
    {
        return $this->hasMany(EmployeeBenefit::class, 'benefit_catalog_id');
    }
}
