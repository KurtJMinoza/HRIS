<?php

namespace App\Services;

use App\Models\PayComponent;
use Illuminate\Support\Arr;

class PayComponentService
{
    /**
     * Central list of mandatory PH payroll components.
     *
     * @return array<int, array<string, mixed>>
     */
    public function systemProtectedBlueprints(): array
    {
        return [
            [
                'code' => 'BASIC_SALARY',
                'name' => 'Basic Salary',
                'type' => PayComponent::TYPE_EARNING,
                'category' => 'Basic Salary',
                'calculation_type' => PayComponent::CALC_FIXED,
                'default_value' => 0,
                'is_taxable' => true,
                'contributes_sss' => false,
                'contributes_philhealth' => false,
                'contributes_pagibig' => false,
                'is_proratable' => true,
            ],
            [
                'code' => 'THIRTEENTH_MONTH',
                'name' => '13th Month Pay',
                'type' => PayComponent::TYPE_EARNING,
                'category' => 'Bonus',
                'calculation_type' => PayComponent::CALC_PERCENT_BASIC,
                'default_value' => 8.3333,
                'is_taxable' => false,
                'contributes_sss' => false,
                'contributes_philhealth' => false,
                'contributes_pagibig' => false,
                'is_proratable' => true,
            ],
            [
                'code' => 'WITHHOLDING_TAX',
                'name' => 'Withholding Tax',
                'type' => PayComponent::TYPE_DEDUCTION,
                'category' => 'Government Deduction',
                'calculation_type' => PayComponent::CALC_FORMULA,
                'formula' => 'DEFAULT_VALUE',
                'default_value' => 0,
                'is_taxable' => false,
                'contributes_sss' => false,
                'contributes_philhealth' => false,
                'contributes_pagibig' => false,
                'is_proratable' => false,
            ],
            [
                'code' => 'SSS',
                'name' => 'SSS Contribution',
                'type' => PayComponent::TYPE_DEDUCTION,
                'category' => 'Government Deduction',
                'calculation_type' => PayComponent::CALC_FORMULA,
                'formula' => 'DEFAULT_VALUE',
                'default_value' => 0,
                'is_taxable' => false,
                'contributes_sss' => false,
                'contributes_philhealth' => false,
                'contributes_pagibig' => false,
                'is_proratable' => false,
            ],
            [
                'code' => 'PHILHEALTH',
                'name' => 'PhilHealth Contribution',
                'type' => PayComponent::TYPE_DEDUCTION,
                'category' => 'Government Deduction',
                'calculation_type' => PayComponent::CALC_FORMULA,
                'formula' => 'DEFAULT_VALUE',
                'default_value' => 0,
                'is_taxable' => false,
                'contributes_sss' => false,
                'contributes_philhealth' => false,
                'contributes_pagibig' => false,
                'is_proratable' => false,
            ],
            [
                'code' => 'PAGIBIG',
                'name' => 'Pag-IBIG Contribution',
                'type' => PayComponent::TYPE_DEDUCTION,
                'category' => 'Government Deduction',
                'calculation_type' => PayComponent::CALC_FORMULA,
                'formula' => 'DEFAULT_VALUE',
                'default_value' => 0,
                'is_taxable' => false,
                'contributes_sss' => false,
                'contributes_philhealth' => false,
                'contributes_pagibig' => false,
                'is_proratable' => false,
            ],
        ];
    }

    public function isSystemProtected(PayComponent $component): bool
    {
        return (bool) ($component->is_system_protected ?? false);
    }

    /**
     * Safe formula check for payroll component formulas.
     */
    public function assertSafeFormula(?string $formula): bool
    {
        $value = strtoupper(trim((string) $formula));
        if ($value === '') {
            return true;
        }

        $tokens = ['BASIC', 'GROSS', 'DEFAULT_VALUE', 'HOURS', 'HOURLY_RATE', 'DAILY_RATE'];
        $value = str_replace($tokens, '1', $value);

        return (bool) preg_match('/^[0-9\.\+\-\*\/\(\)\s]+$/', $value);
    }

    /**
     * System components stay flagged as protected, but HR may edit labels, calculation, flags, and dates.
     * The integration layer keys off stable `code` values (e.g. BASIC_SALARY, SSS) — those cannot change.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function stripProtectedMutations(PayComponent $component, array $payload): array
    {
        if (! $this->isSystemProtected($component)) {
            return $payload;
        }

        if (array_key_exists('code', $payload)
            && strtoupper(trim((string) $payload['code'])) !== strtoupper(trim((string) $component->code))) {
            $payload = Arr::except($payload, ['code']);
        }

        $payload['is_system_protected'] = true;

        return $payload;
    }
}
