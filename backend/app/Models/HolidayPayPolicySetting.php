<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayPayPolicySetting extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const UNWORKED_NO_PAY = 'no_work_no_pay';

    public const UNWORKED_COVERED = 'covered_employees';

    public const UNWORKED_SELECTED = 'selected_employment_types';

    public const UNWORKED_ALL = 'all_employees';

    protected $fillable = [
        'policy_id',
        'company_id',
        'branch_id',
        'holiday_id',
        'holiday_key',
        'holiday_type',
        'unworked_pay_policy',
        'eligible_employment_types',
        'pay_unworked',
        'require_previous_workday_attendance',
        'allow_paid_leave',
        'allow_official_business',
        'allow_training',
        'allow_travel',
        'allow_rest_day_lookup',
        'allow_company_nonworking_lookup',
        'ignore_previous_attendance',
        'always_pay',
        'always_pay_unworked',
        'enable_successive_rule',
        'disable_attendance_qualification',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'eligible_employment_types' => 'array',
            'pay_unworked' => 'boolean',
            'require_previous_workday_attendance' => 'boolean',
            'allow_paid_leave' => 'boolean',
            'allow_official_business' => 'boolean',
            'allow_training' => 'boolean',
            'allow_travel' => 'boolean',
            'allow_rest_day_lookup' => 'boolean',
            'allow_company_nonworking_lookup' => 'boolean',
            'ignore_previous_attendance' => 'boolean',
            'always_pay' => 'boolean',
            'always_pay_unworked' => 'boolean',
            'enable_successive_rule' => 'boolean',
            'disable_attendance_qualification' => 'boolean',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return array<string, mixed> */
    public function toPolicyOverlay(): array
    {
        $overlay = [];

        if ($this->unworked_pay_policy) {
            $overlay['per_holiday_unworked_pay_policy'] = $this->unworked_pay_policy;
        }
        if (is_array($this->eligible_employment_types) && $this->eligible_employment_types !== []) {
            $overlay['per_holiday_eligible_employment_types'] = $this->eligible_employment_types;
        }
        if ($this->pay_unworked !== null) {
            $overlay['per_holiday_pay_unworked'] = (bool) $this->pay_unworked;
        }
        if ($this->always_pay_unworked || $this->always_pay) {
            $overlay['always_pay_unworked'] = true;
            $overlay['always_pay'] = true;
        }
        if ($this->ignore_previous_attendance) {
            $overlay['ignore_previous_attendance'] = true;
        }
        if ($this->disable_attendance_qualification) {
            $overlay['disable_attendance_qualification'] = true;
        }

        $attendanceMap = [
            'require_previous_workday_attendance' => 'require_previous_workday_presence',
            'allow_paid_leave' => 'paid_leave_qualifies',
            'allow_rest_day_lookup' => 'skip_rest_days',
            'allow_company_nonworking_lookup' => 'skip_company_non_working_days',
        ];

        foreach ($attendanceMap as $column => $key) {
            if ($this->{$column} !== null) {
                $overlay['attendance'][$key] = (bool) $this->{$column};
            }
        }

        if ($this->enable_successive_rule !== null) {
            $overlay['successive']['enabled'] = (bool) $this->enable_successive_rule;
        }

        return $overlay;
    }

    public static function holidayKeyForRow(array $holiday): string
    {
        if (! empty($holiday['id'])) {
            return 'id:'.(int) $holiday['id'];
        }

        $date = (string) ($holiday['date'] ?? '');
        $md = strlen($date) >= 10 ? substr($date, 5, 5) : '00-00';
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', (string) ($holiday['name'] ?? 'holiday')) ?? 'holiday');

        return 'seed:'.$md.':'.$slug;
    }
}
