<?php

namespace App\Models;

use App\Support\TextSanitizer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected static function booted(): void
    {
        static::saving(function (Holiday $holiday) {
            if (is_string($holiday->name)) {
                $holiday->name = TextSanitizer::clean($holiday->name, $holiday->name) ?? $holiday->name;
            }
            if (is_string($holiday->description)) {
                $holiday->description = TextSanitizer::clean($holiday->description);
            }
        });
    }

    protected $fillable = [
        'date',
        'name',
        'type',
        'scope',
        'company_id',
        'branch_id',
        'division_id',
        'department_id',
        'section_unit_id',
        'employee_id',
        'coverage_type',
        'coverage_ids',
        'is_swap',
        'original_date',
        'description',
        'regions',
        'is_recurring',
        'status',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'original_date' => 'date:Y-m-d',
        'regions' => 'array',
        'coverage_ids' => 'array',
        'is_recurring' => 'boolean',
        'is_swap' => 'boolean',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'division_id' => 'integer',
        'department_id' => 'integer',
        'section_unit_id' => 'integer',
        'employee_id' => 'integer',
    ];

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

    public function sectionUnit(): BelongsTo
    {
        return $this->belongsTo(SectionUnit::class, 'section_unit_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function isSwapHoliday(): bool
    {
        return (bool) $this->is_swap;
    }

    public function hasCoverage(): bool
    {
        return $this->coverage_type !== null && ! empty($this->coverage_ids);
    }

    public function getCoverageIds(): array
    {
        return is_array($this->coverage_ids) ? $this->coverage_ids : [];
    }
}
