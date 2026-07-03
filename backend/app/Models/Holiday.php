<?php

namespace App\Models;

use App\Support\TextSanitizer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function holidayScopes(): HasMany
    {
        return $this->hasMany(HolidayScope::class);
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

    /**
     * Sync the holiday_scopes table from the current coverage configuration.
     * Call this after saving a holiday to keep holiday_scopes in sync.
     */
    public function syncHolidayScopes(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('holiday_scopes')) {
            return;
        }

        $this->holidayScopes()->delete();

        $coverageType = $this->coverage_type;
        $coverageIds = $this->getCoverageIds();

        if ($coverageType === null || empty($coverageIds)) {
            $scope = $this->scope ?? 'nationwide';
            if (in_array($scope, ['company', 'branch', 'division', 'department', 'section_unit', 'employee'], true)) {
                $legacyMap = [
                    'company' => ['column' => 'company_id', 'scope_type' => 'company'],
                    'branch' => ['column' => 'branch_id', 'scope_type' => 'branch'],
                    'division' => ['column' => 'division_id', 'scope_type' => 'division'],
                    'department' => ['column' => 'department_id', 'scope_type' => 'department'],
                    'section_unit' => ['column' => 'section_unit_id', 'scope_type' => 'section'],
                    'employee' => ['column' => 'employee_id', 'scope_type' => 'employee'],
                ];
                if (isset($legacyMap[$scope])) {
                    $cfg = $legacyMap[$scope];
                    $id = $this->{$cfg['column']};
                    if ($id !== null) {
                        $this->holidayScopes()->create([
                            'scope_type' => $cfg['scope_type'],
                            'scope_id' => (int) $id,
                            'company_id' => $this->company_id,
                            'branch_id' => $this->branch_id,
                            'division_id' => $this->division_id,
                            'department_id' => $this->department_id,
                            'section_id' => $this->section_unit_id,
                            'employee_id' => $this->employee_id,
                        ]);
                    }
                }
            }

            return;
        }

        $normalizedType = match ($coverageType) {
            'company', 'companies', 'selected_companies' => 'company',
            'branch', 'branches', 'selected_branches' => 'branch',
            'division', 'divisions', 'selected_divisions' => 'division',
            'department', 'departments', 'selected_departments' => 'department',
            'section', 'sections', 'section_units', 'selected_sections' => 'section',
            'employee', 'employees', 'selected_employees' => 'employee',
            default => null,
        };

        if ($normalizedType === null) {
            return;
        }

        foreach ($coverageIds as $scopeId) {
            $scopeId = (int) $scopeId;
            $record = [
                'scope_type' => $normalizedType,
                'scope_id' => $scopeId,
            ];

            if ($normalizedType === 'company') {
                $record['company_id'] = $scopeId;
            } elseif ($normalizedType === 'branch') {
                $branch = \App\Models\Branch::find($scopeId);
                $record['company_id'] = $branch?->company_id;
                $record['branch_id'] = $scopeId;
            } elseif ($normalizedType === 'division') {
                $division = \App\Models\Division::find($scopeId);
                $record['company_id'] = $division?->company_id;
                $record['branch_id'] = $division?->branch_id;
                $record['division_id'] = $scopeId;
            } elseif ($normalizedType === 'department') {
                $department = \App\Models\Department::with('branch:id,company_id')->find($scopeId);
                    $record['company_id'] = $department?->branch?->company_id;
                $record['branch_id'] = $department?->branch_id;
                $record['division_id'] = $department?->division_id;
                $record['department_id'] = $scopeId;
            } elseif ($normalizedType === 'section') {
                $section = \App\Models\SectionUnit::find($scopeId);
                $record['company_id'] = $section?->company_id;
                $record['branch_id'] = $section?->branch_id;
                $record['division_id'] = $section?->division_id;
                $record['department_id'] = $section?->department_id;
                $record['section_id'] = $scopeId;
            } elseif ($normalizedType === 'employee') {
                $employee = \App\Models\User::find($scopeId);
                $record['company_id'] = $employee?->getEffectiveCompanyId();
                $record['branch_id'] = $employee?->branch_id;
                $record['division_id'] = $employee?->division_id;
                $record['department_id'] = $employee?->department_id;
                $record['section_id'] = $employee?->section_unit_id;
                $record['employee_id'] = $scopeId;
            }

            $this->holidayScopes()->create($record);
        }
    }
}
