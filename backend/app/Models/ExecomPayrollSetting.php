<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecomPayrollSetting extends Model
{
    protected $fillable = [
        'company_id',
        'apply_custom_deductions',
        'apply_allowances',
        'allow_paid_leave',
        'allow_overtime',
        'allow_holiday_pay',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'apply_custom_deductions' => 'boolean',
            'apply_allowances' => 'boolean',
            'allow_paid_leave' => 'boolean',
            'allow_overtime' => 'boolean',
            'allow_holiday_pay' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function defaults(?int $companyId = null): array
    {
        return [
            'company_id' => $companyId,
            'apply_custom_deductions' => true,
            'apply_allowances' => true,
            'allow_paid_leave' => true,
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
        ];
    }

    public static function forCompany(?int $companyId): self
    {
        if ($companyId !== null) {
            $setting = self::query()
                ->where('company_id', $companyId)
                ->first();

            if ($setting instanceof self) {
                return $setting;
            }
        }

        // Quick Setup saves the global row (company_id null). Company-scoped EXECOM
        // employees must inherit that when no company-specific override exists.
        $global = self::query()
            ->whereNull('company_id')
            ->first();

        if ($global instanceof self) {
            return $global;
        }

        return new self(self::defaults($companyId));
    }

    /**
     * @return array{
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }
     */
    public function toPolicyArray(): array
    {
        return [
            'apply_custom_deductions' => (bool) $this->apply_custom_deductions,
            'apply_allowances' => (bool) $this->apply_allowances,
            'allow_paid_leave' => (bool) ($this->allow_paid_leave ?? true),
            'allow_overtime' => (bool) $this->allow_overtime,
            'allow_holiday_pay' => (bool) $this->allow_holiday_pay,
        ];
    }
}
