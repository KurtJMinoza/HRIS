<?php

namespace App\Models;

use App\Support\EmployeeProfileCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBenefit extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'employee_benefits';

    protected $fillable = [
        'user_id',
        'benefit_catalog_id',
        'effective_date',
        'status',
        'metadata',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (EmployeeBenefit $benefit): void {
            if ($benefit->user_id) {
                EmployeeProfileCache::forgetForUser((int) $benefit->user_id);
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function benefitCatalog(): BelongsTo
    {
        return $this->belongsTo(BenefitCatalog::class, 'benefit_catalog_id');
    }
}
