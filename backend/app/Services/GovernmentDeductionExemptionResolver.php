<?php

namespace App\Services;

use App\Models\EmployeeGovernmentDeductionSetting;
use App\Models\EmployeeGovernmentDeductionSettingAudit;
use App\Models\User;
use App\Support\GovernmentExemptionCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GovernmentDeductionExemptionResolver
{
    public const PAYROLL_REGULAR = 'regular';

    public const PAYROLL_EXECOM = 'execom';

    /** @var array<string, string> */
    public const DEDUCTION_FIELDS = [
        'sss' => 'deduct_sss',
        'philhealth' => 'deduct_philhealth',
        'pagibig' => 'deduct_pagibig',
        'withholding_tax' => 'deduct_withholding_tax',
    ];

    /** @var array<string, string> */
    public const LABELS = [
        'sss' => 'SSS',
        'philhealth' => 'PhilHealth',
        'pagibig' => 'Pag-IBIG',
        'withholding_tax' => 'Withholding Tax',
    ];

    public function defaultPayload(?EmployeeGovernmentDeductionSetting $setting = null): array
    {
        $deductSss = $setting ? (bool) $setting->deduct_sss : true;
        $deductPhilhealth = $setting ? (bool) $setting->deduct_philhealth : true;
        $deductPagibig = $setting ? (bool) $setting->deduct_pagibig : true;
        $deductWithholdingTax = $setting ? (bool) $setting->deduct_withholding_tax : true;

        return [
            'id' => $setting?->id,
            'employee_id' => $setting?->user_id,
            'deduct_sss' => $deductSss,
            'deduct_philhealth' => $deductPhilhealth,
            'deduct_pagibig' => $deductPagibig,
            'deduct_withholding_tax' => $deductWithholdingTax,
            'exempt_sss' => ! $deductSss,
            'exempt_philhealth' => ! $deductPhilhealth,
            'exempt_pagibig' => ! $deductPagibig,
            'exempt_withholding_tax' => ! $deductWithholdingTax,
            'exempt_all_government_deductions' => ! $deductSss && ! $deductPhilhealth && ! $deductPagibig && ! $deductWithholdingTax,
            'applies_to_regular_payroll' => $setting ? $setting->applies_to_regular_payroll !== false : true,
            'applies_to_execom_payroll' => $setting ? $setting->applies_to_execom_payroll !== false : true,
            'exemption_reason' => $setting?->exemption_reason,
            'is_active' => $setting ? $setting->is_active !== false : true,
            'created_by' => $setting?->created_by,
            'updated_by' => $setting?->updated_by,
            'updated_at' => optional($setting?->updated_at)->toDateTimeString(),
        ];
    }

    public function settingsForEmployee(int $userId): array
    {
        $setting = EmployeeGovernmentDeductionSetting::query()
            ->where('user_id', $userId)
            ->first();

        return $this->defaultPayload($setting);
    }

    /**
     * Resolve exemption flags for a payroll run (shared by regular, consultant, and EXECOM payroll).
     *
     * @return array{
     *     deduct_sss: bool,
     *     deduct_philhealth: bool,
     *     deduct_pagibig: bool,
     *     deduct_withholding_tax: bool,
     *     exemption_reason: ?string,
     *     active_for_period: bool,
     *     government_exemption_found: bool,
     *     applies_to_regular_payroll: bool,
     *     applies_to_execom_payroll: bool,
     *     payroll_type: string,
     *     exempted_types: list<string>
     * }
     */
    public function resolve(
        int $employeeId,
        string $payrollType,
        Carbon $payrollPeriodStart,
        Carbon $payrollPeriodEnd
    ): array {
        $employee = User::query()->find($employeeId);
        if (! $employee) {
            return $this->emptyResolvedPayload($this->normalizePayrollType($payrollType));
        }

        return $this->activeSettingsForPayrollPeriod($employee, $payrollPeriodStart, $payrollPeriodEnd, $payrollType);
    }

    public function activeSettingsForPayrollPeriod(User $employee, Carbon $from, Carbon $to, string $payrollType = self::PAYROLL_REGULAR): array
    {
        $setting = EmployeeGovernmentDeductionSetting::query()
            ->where('user_id', (int) $employee->id)
            ->first();

        $normalizedPayrollType = $this->normalizePayrollType($payrollType);
        $payload = $this->defaultPayload($setting);
        $governmentExemptionFound = $setting !== null;
        $scopeApplies = $setting !== null && $this->appliesToPayrollType($setting, $normalizedPayrollType);
        $settingActive = $setting !== null && $setting->is_active !== false;
        $active = $governmentExemptionFound && $scopeApplies && $settingActive;

        if (! $active) {
            foreach (self::DEDUCTION_FIELDS as $field) {
                $payload[$field] = true;
            }
            $payload['exempt_sss'] = false;
            $payload['exempt_philhealth'] = false;
            $payload['exempt_pagibig'] = false;
            $payload['exempt_withholding_tax'] = false;
            $payload['exempt_all_government_deductions'] = false;
        }

        $payload['payroll_type'] = $normalizedPayrollType;
        $payload['active_for_period'] = $active;
        $payload['government_exemption_found'] = $governmentExemptionFound;
        $payload['scope_applies'] = $scopeApplies;
        $payload['exempted_types'] = collect(self::DEDUCTION_FIELDS)
            ->filter(fn (string $field): bool => ! (bool) ($payload[$field] ?? true))
            ->keys()
            ->values()
            ->all();

        $this->logResolutionIfNeeded($employee, $from, $to, $payload, $setting);

        return $payload;
    }

    public function upsertForEmployee(User $employee, array $payload, ?User $actor = null): EmployeeGovernmentDeductionSetting
    {
        return DB::transaction(function () use ($employee, $payload, $actor): EmployeeGovernmentDeductionSetting {
            $setting = EmployeeGovernmentDeductionSetting::query()
                ->where('user_id', (int) $employee->id)
                ->lockForUpdate()
                ->first();

            $old = $setting ? $this->defaultPayload($setting) : null;
            $setting ??= new EmployeeGovernmentDeductionSetting(['user_id' => (int) $employee->id]);

            foreach (self::DEDUCTION_FIELDS as $field) {
                if (array_key_exists($field, $payload)) {
                    $setting->{$field} = (bool) $payload[$field];
                }
            }
            $explicitExemptFields = [
                'exempt_sss' => 'deduct_sss',
                'exempt_philhealth' => 'deduct_philhealth',
                'exempt_pagibig' => 'deduct_pagibig',
                'exempt_withholding_tax' => 'deduct_withholding_tax',
            ];
            if (array_key_exists('exempt_all_government_deductions', $payload) && (bool) $payload['exempt_all_government_deductions']) {
                foreach ($explicitExemptFields as $deductField) {
                    $setting->{$deductField} = false;
                }
            }
            foreach ($explicitExemptFields as $exemptField => $deductField) {
                if (array_key_exists($exemptField, $payload)) {
                    $setting->{$deductField} = ! (bool) $payload[$exemptField];
                }
            }
            foreach (['applies_to_regular_payroll', 'applies_to_execom_payroll'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $setting->{$field} = (bool) $payload[$field];
                }
            }
            if (array_key_exists('is_active', $payload)) {
                $setting->is_active = (bool) $payload['is_active'];
            }

            if (array_key_exists('exemption_reason', $payload)) {
                $setting->exemption_reason = $payload['exemption_reason'] !== null
                    ? trim((string) $payload['exemption_reason'])
                    : null;
            }
            if (! $setting->exists) {
                $setting->created_by = $actor?->id;
            }
            $setting->updated_by = $actor?->id;
            $setting->save();

            $this->auditChanges($employee, $old, $this->defaultPayload($setting), $actor);
            GovernmentExemptionCache::clearPayrollCaches((int) $employee->id);

            return $setting;
        });
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  list<string>  $deductionTypes
     */
    public function bulkSet(array $employeeIds, array $deductionTypes, bool $deduct, array $payload, ?User $actor = null): int
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        $types = array_values(array_intersect($deductionTypes, array_keys(self::DEDUCTION_FIELDS)));
        if ($ids === [] || $types === []) {
            return 0;
        }

        $employees = User::query()->whereIn('id', $ids)->get(['id']);
        foreach ($employees as $employee) {
            $update = [
                'exemption_reason' => $payload['exemption_reason'] ?? null,
                'applies_to_regular_payroll' => $payload['applies_to_regular_payroll'] ?? true,
                'applies_to_execom_payroll' => $payload['applies_to_execom_payroll'] ?? true,
            ];
            foreach ($types as $type) {
                $update[self::DEDUCTION_FIELDS[$type]] = $deduct;
            }
            $this->upsertForEmployee($employee, $update, $actor);
        }

        GovernmentExemptionCache::clearPayrollCaches();

        return $employees->count();
    }

    public function applyToStatutory(array $statutory, array $settings, array $context = []): array
    {
        foreach (['sss', 'philhealth', 'pagibig'] as $type) {
            $field = self::DEDUCTION_FIELDS[$type];
            $original = (float) data_get($statutory, $type.'.employee_amount', 0);
            if ((bool) ($settings[$field] ?? true)) {
                $this->logPayrollApplication($type, $settings, $context, $original, $original);
                continue;
            }
            if (! is_array($statutory[$type] ?? null)) {
                $statutory[$type] = [];
            }
            foreach (['employee_amount', 'employer_amount', 'ec_amount', 'total_amount', 'overall_total'] as $amountField) {
                if (array_key_exists($amountField, $statutory[$type])) {
                    $statutory[$type][$amountField] = 0.0;
                }
            }
            $statutory[$type]['employee_amount'] = 0.0;
            $statutory[$type]['employer_amount'] = 0.0;
            if ($type === 'sss') {
                $statutory[$type]['ec_amount'] = 0.0;
            }
            $statutory[$type]['exempted'] = true;
            $statutory[$type]['exemption_reason'] = $settings['exemption_reason'] ?? null;
            $this->logPayrollApplication($type, $settings, $context, $original, 0.0);
            $statutory[$type]['exemption_note'] = self::LABELS[$type].' — Exempted';
        }

        $employeeTotal = 0.0;
        $employerTotal = 0.0;
        foreach (['sss', 'philhealth', 'pagibig'] as $type) {
            $row = is_array($statutory[$type] ?? null) ? $statutory[$type] : [];
            if (! empty($row['exempted'])) {
                $statutory[$type]['exemption_note'] = self::LABELS[$type].' - Government deduction exempted';
            }
            $employeeTotal += (float) ($row['employee_amount'] ?? 0);
            $employerTotal += (float) ($row['employer_amount'] ?? 0);
            if ($type === 'sss') {
                $employerTotal += (float) ($row['ec_amount'] ?? 0);
            }
        }
        if (! is_array($statutory['totals'] ?? null)) {
            $statutory['totals'] = [];
        }
        $statutory['totals']['employee_deduction'] = round($employeeTotal, 2);
        $statutory['totals']['employer_liability'] = round($employerTotal, 2);
        $statutory['totals']['combined_remittance'] = round($employeeTotal + $employerTotal, 2);

        return $statutory;
    }

    public function applyToWithholding(array $withholding, float $monthlyAmount, array $settings, array $context = []): array
    {
        $original = (float) ($withholding['withholding_per_month'] ?? $monthlyAmount);
        if ((bool) ($settings['deduct_withholding_tax'] ?? true)) {
            $this->logPayrollApplication('withholding_tax', $settings, $context, $original, $monthlyAmount);
            return [$withholding, $monthlyAmount];
        }

        $withholding['withholding_per_month'] = 0.0;
        $withholding['withholding_per_period'] = 0.0;
        $withholding['exempted'] = true;
        $withholding['exemption_reason'] = $settings['exemption_reason'] ?? null;
        $this->logPayrollApplication('withholding_tax', $settings, $context, $original, 0.0);
        $withholding['exemption_note'] = self::LABELS['withholding_tax'].' — Exempted';

        $withholding['exemption_note'] = self::LABELS['withholding_tax'].' - Government deduction exempted';

        return [$withholding, 0.0];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResolvedPayload(string $payrollType): array
    {
        return [
            'deduct_sss' => true,
            'deduct_philhealth' => true,
            'deduct_pagibig' => true,
            'deduct_withholding_tax' => true,
            'exempt_sss' => false,
            'exempt_philhealth' => false,
            'exempt_pagibig' => false,
            'exempt_withholding_tax' => false,
            'exempt_all_government_deductions' => false,
            'exemption_reason' => null,
            'active_for_period' => false,
            'government_exemption_found' => false,
            'applies_to_regular_payroll' => true,
            'applies_to_execom_payroll' => true,
            'payroll_type' => $payrollType,
            'exempted_types' => [],
        ];
    }

    private function normalizePayrollType(string $payrollType): string
    {
        return strtolower(trim($payrollType)) === self::PAYROLL_EXECOM
            ? self::PAYROLL_EXECOM
            : self::PAYROLL_REGULAR;
    }

    private function appliesToPayrollType(EmployeeGovernmentDeductionSetting $setting, string $payrollType): bool
    {
        if ($payrollType === self::PAYROLL_EXECOM) {
            return $setting->applies_to_execom_payroll !== false;
        }

        return $setting->applies_to_regular_payroll !== false;
    }

    private function auditChanges(User $employee, ?array $old, array $new, ?User $actor): void
    {
        $auditFields = array_merge(self::DEDUCTION_FIELDS, [
            'regular_payroll_scope' => 'applies_to_regular_payroll',
            'execom_payroll_scope' => 'applies_to_execom_payroll',
            'setting_active' => 'is_active',
        ]);

        foreach ($auditFields as $type => $field) {
            $oldValue = $old[$field] ?? null;
            $newValue = (bool) ($new[$field] ?? true);
            if ($old !== null && (bool) $oldValue === $newValue) {
                continue;
            }

            EmployeeGovernmentDeductionSettingAudit::query()->create([
                'employee_id' => (int) $employee->id,
                'deduction_type' => $type,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'changed_by' => $actor?->id,
                'reason' => $new['exemption_reason'] ?? null,
            ]);
            Log::info('government_deduction_exemption.audit', [
                'event' => $old === null ? 'exemption_created' : ($type === 'setting_active' && ! $newValue ? 'exemption_removed' : 'exemption_updated'),
                'employee_id' => (int) $employee->id,
                'employee_name' => $employee->display_name,
                'deduction_type' => $type,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'changed_by' => $actor?->id,
                'reason' => $new['exemption_reason'] ?? null,
            ]);
        }
    }

    private function logResolutionIfNeeded(
        User $employee,
        Carbon $from,
        Carbon $to,
        array $payload,
        ?EmployeeGovernmentDeductionSetting $setting
    ): void {
        Log::info('government_deduction_exemption.resolve', [
            'payroll_run_id' => null,
            'employee_id' => (int) $employee->id,
            'employee_name' => $employee->display_name,
            'payroll_type' => $payload['payroll_type'] ?? null,
            'payroll_period_start' => $from->toDateString(),
            'payroll_period_end' => $to->toDateString(),
            'has_active_execom_profile' => method_exists($employee, 'hasActiveExecomAssignment')
                ? $employee->hasActiveExecomAssignment($from, $to)
                : null,
            'government_exemption_found' => (bool) ($payload['government_exemption_found'] ?? false),
            'scope_applies' => (bool) ($payload['scope_applies'] ?? false),
            'applies_to_regular_payroll' => (bool) ($payload['applies_to_regular_payroll'] ?? true),
            'applies_to_execom_payroll' => (bool) ($payload['applies_to_execom_payroll'] ?? true),
            'deduct_sss' => (bool) ($payload['deduct_sss'] ?? true),
            'deduct_philhealth' => (bool) ($payload['deduct_philhealth'] ?? true),
            'deduct_pagibig' => (bool) ($payload['deduct_pagibig'] ?? true),
            'deduct_wtax' => (bool) ($payload['deduct_withholding_tax'] ?? true),
            'active_for_period' => (bool) ($payload['active_for_period'] ?? false),
            'included_or_excluded' => (bool) ($payload['active_for_period'] ?? false) ? 'exemption_applied' : 'no_active_exemption',
            'exclusion_reason' => ! (bool) ($payload['active_for_period'] ?? false)
                ? $this->inactiveReason($payload, $setting)
                : null,
            'exempted_types' => $payload['exempted_types'] ?? [],
        ]);
    }

    private function inactiveReason(array $payload, ?EmployeeGovernmentDeductionSetting $setting): ?string
    {
        if ($setting === null) {
            return 'no exemption setting';
        }
        if ($setting->is_active === false) {
            return 'exemption inactive';
        }
        if (! (bool) ($payload['scope_applies'] ?? false)) {
            return 'exemption does not apply to payroll type';
        }
        return null;
    }

    private function logPayrollApplication(string $deductionType, array $settings, array $context, float $originalAmount, float $finalAmount): void
    {
        try {
            Log::info('government_deduction_exemption.payroll_application', [
                'employee_id' => $context['employee_id'] ?? null,
                'employee_name' => $context['employee_name'] ?? null,
                'payroll_run_id' => $context['payroll_run_id'] ?? null,
                'payroll_period_start' => $context['payroll_period_start'] ?? null,
                'payroll_period_end' => $context['payroll_period_end'] ?? null,
                'deduction_type' => $deductionType,
                'exemption_found' => (bool) ($settings['government_exemption_found'] ?? false),
                'exemption_active' => (bool) ($settings['active_for_period'] ?? false),
                'original_computed_amount' => round($originalAmount, 2),
                'final_amount' => round($finalAmount, 2),
                'exemption_reason' => $settings['exemption_reason'] ?? null,
            ]);
        } catch (\Throwable) {
            // Logging must not break isolated payroll unit tests or payroll computation.
        }
    }
}
