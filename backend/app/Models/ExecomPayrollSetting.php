<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecomPayrollSetting extends Model
{
    protected $fillable = [
        'company_id',
        'apply_government_deductions',
        'apply_custom_deductions',
        'apply_allowances',
        'allow_overtime',
        'allow_holiday_pay',
        'auto_present_attendance_reports',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'apply_government_deductions' => 'boolean',
            'apply_custom_deductions' => 'boolean',
            'apply_allowances' => 'boolean',
            'allow_overtime' => 'boolean',
            'allow_holiday_pay' => 'boolean',
            'auto_present_attendance_reports' => 'boolean',
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
            'apply_government_deductions' => true,
            'apply_custom_deductions' => true,
            'apply_allowances' => true,
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
            'auto_present_attendance_reports' => true,
        ];
    }

    public static function forCompany(?int $companyId): self
    {
        $setting = self::query()
            ->where('company_id', $companyId)
            ->first();

        if ($setting instanceof self) {
            return $setting;
        }

        return new self(self::defaults($companyId));
    }
}
