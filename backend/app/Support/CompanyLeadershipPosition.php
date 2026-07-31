<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Flexible leadership position names (Company Head, OIC, retired assistant/OIC types). */
final class CompanyLeadershipPosition
{
    public static function normalizeName(string $name): string
    {
        $normalized = Str::lower(trim(str_replace(['-', '_'], ' ', $name)));

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    public static function isCompanyHead(string $name): bool
    {
        return self::normalizeName($name) === 'company head';
    }

    public static function isOfficerInCharge(string $name): bool
    {
        $normalized = self::normalizeName($name);

        return str_contains($normalized, 'officer in charge');
    }

    public static function isRetiredCompanyLeadershipType(string $name): bool
    {
        $normalized = self::normalizeName($name);

        return in_array($normalized, ['co company head', 'assistant company head'], true);
    }

    /**
     * Position types removed from leadership pickers (assistants / unit OICs / non-head helpers).
     * Company Officer-in-Charge stays assignable.
     */
    public static function isRetiredAssignableType(string $organizationLevel, string $name): bool
    {
        $normalized = self::normalizeName($name);

        return match ($organizationLevel) {
            'company' => self::isRetiredCompanyLeadershipType($name),
            'branch' => in_array($normalized, ['assistant branch head', 'branch oic'], true)
                || self::isAssistantOrUnitOicName($normalized, 'branch'),
            'division' => in_array($normalized, ['assistant division head', 'division oic'], true)
                || self::isAssistantOrUnitOicName($normalized, 'division'),
            'department' => $normalized !== 'department head',
            'section_unit' => $normalized !== 'section head',
            default => false,
        };
    }

    public static function displayName(string $name): string
    {
        if (self::isOfficerInCharge($name)) {
            return 'Officer in Charge';
        }

        return $name;
    }

    private static function isAssistantOrUnitOicName(string $normalized, string $level): bool
    {
        if (str_starts_with($normalized, 'assistant ')) {
            return true;
        }

        return $normalized === $level.' oic'
            || $normalized === 'assistant oic'
            || str_ends_with($normalized, ' oic');
    }
}
