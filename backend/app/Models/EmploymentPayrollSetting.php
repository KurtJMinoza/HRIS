<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmploymentPayrollSetting extends Model
{
    /** @var list<string> */
    public const EMPLOYMENT_TYPES = [
        'regular',
        'probationary',
        'project_based',
        'consultant',
    ];

    /** Employment classes that never receive paid leave. */
    public const UNPAID_LEAVE_EMPLOYMENT_TYPES = [
        'probationary',
        'project_based',
    ];

    protected $fillable = [
        'company_id',
        'employment_type',
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

    /**
     * @return array{
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }
     */
    public static function defaults(?string $employmentType = null): array
    {
        $employmentType = $employmentType !== null
            ? self::normalizeEmploymentType($employmentType)
            : null;

        return [
            'apply_custom_deductions' => true,
            'apply_allowances' => true,
            'allow_paid_leave' => ! self::isUnpaidLeaveEmploymentType($employmentType ?? ''),
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
        ];
    }

    public static function isUnpaidLeaveEmploymentType(string $employmentType): bool
    {
        return in_array(self::normalizeEmploymentType($employmentType), self::UNPAID_LEAVE_EMPLOYMENT_TYPES, true);
    }

    public static function normalizeEmploymentType(string $employmentType): string
    {
        $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $employmentType) ?? '', '_'));

        return in_array($normalized, self::EMPLOYMENT_TYPES, true) ? $normalized : $normalized;
    }

    public static function forCompanyAndType(?int $companyId, string $employmentType): self
    {
        $employmentType = self::normalizeEmploymentType($employmentType);

        if ($companyId !== null) {
            $companyRow = self::query()
                ->where('company_id', $companyId)
                ->where('employment_type', $employmentType)
                ->first();

            if ($companyRow instanceof self) {
                return $companyRow;
            }
        }

        $global = self::query()
            ->whereNull('company_id')
            ->where('employment_type', $employmentType)
            ->first();

        if ($global instanceof self) {
            return $global;
        }

        return new self(array_merge(self::defaults($employmentType), [
            'company_id' => $companyId,
            'employment_type' => $employmentType,
        ]));
    }

    /**
     * @return array{
     *     employment_type: string,
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }
     */
    public function toPolicyArray(): array
    {
        $employmentType = (string) $this->employment_type;
        $allowPaidLeave = self::isUnpaidLeaveEmploymentType($employmentType)
            ? false
            : (bool) ($this->allow_paid_leave ?? true);

        return [
            'employment_type' => $employmentType,
            'apply_custom_deductions' => (bool) $this->apply_custom_deductions,
            'apply_allowances' => (bool) $this->apply_allowances,
            'allow_paid_leave' => $allowPaidLeave,
            'allow_overtime' => (bool) $this->allow_overtime,
            'allow_holiday_pay' => (bool) $this->allow_holiday_pay,
        ];
    }
}
