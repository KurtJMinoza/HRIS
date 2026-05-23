<?php

namespace App\Support;

use App\Models\PayComponent;

/**
 * Employee pay-component calculation standard overrides ({@see EmployeeCompensationComponent::$calculation_standard_override}).
 *
 * Storage: nullable string — null means use Pay Component Settings default.
 */
final class CalculationStandard
{
    /** @var list<string> */
    private const STORAGE_SLUGS = [
        PayComponent::STANDARD_MONTHLY,
        PayComponent::STANDARD_PAYROLL,
    ];

    /**
     * Normalize request/legacy values into a nullable slug for DB storage.
     * default / blank / unknown → null (use pay component default).
     */
    public static function normalizeForStorage(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = strtolower(trim($raw));
        if ($s === '' || $s === 'default' || $s === 'use_default') {
            return null;
        }
        if (in_array($s, self::STORAGE_SLUGS, true)) {
            return $s;
        }

        return null;
    }

    public static function normalizeDefault(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, PayComponent::CALCULATION_STANDARDS, true)
            ? $normalized
            : PayComponent::STANDARD_MONTHLY;
    }

    /** @return list<string> */
    public static function validationSlugs(): array
    {
        return array_merge(['default', 'use_default'], self::STORAGE_SLUGS);
    }

    /**
     * @return array{
     *     default_calculation_standard: string,
     *     calculation_standard_override: string|null,
     *     resolved_calculation_standard: string,
     *     calculation_standard_source: 'employee_override'|'pay_component_default'
     * }
     */
    public static function resolveMetadata(?string $override, ?string $payComponentDefault): array
    {
        $default = self::normalizeDefault($payComponentDefault);
        $storedOverride = self::normalizeForStorage($override);
        $resolved = $storedOverride ?? $default;

        return [
            'default_calculation_standard' => $default,
            'calculation_standard_override' => $storedOverride,
            'resolved_calculation_standard' => $resolved,
            'calculation_standard_source' => $storedOverride !== null ? 'employee_override' : 'pay_component_default',
        ];
    }

    public static function label(string $standard): string
    {
        return match (self::normalizeDefault($standard)) {
            PayComponent::STANDARD_PAYROLL => 'Payroll Standard',
            default => 'Monthly Standard',
        };
    }
}
