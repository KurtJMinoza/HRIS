<?php

namespace App\Support;

use App\Models\DeductionScheduleSetting;

/**
 * Central definitions for employee pay-component schedule overrides ({@see EmployeeCompensationComponent::$schedule_override}).
 *
 * Storage (DB): nullable string — null means follow company Deduction Schedule Settings for that pay component.
 * Explicit slugs: first_run (15th), second_run (end of month), split (15/30), monthly (legacy: treated like split timing).
 */
final class PayComponentSchedule
{
    public const OVERRIDE_FIRST_RUN = 'first_run';

    public const OVERRIDE_SECOND_RUN = 'second_run';

    public const OVERRIDE_SPLIT = 'split';

    /** @deprecated Retained for validation compatibility; behaves like split for timing. */
    public const OVERRIDE_MONTHLY = 'monthly';

    /** @var list<string> */
    private const STORAGE_SLUGS = [
        self::OVERRIDE_FIRST_RUN,
        self::OVERRIDE_SECOND_RUN,
        self::OVERRIDE_SPLIT,
        self::OVERRIDE_MONTHLY,
    ];

    /**
     * Normalize any raw request/legacy DB value into a nullable slug stored on {@see EmployeeCompensationComponent::$schedule_override}.
     * default / blank / unknown → null (use company default schedule).
     */
    public static function normalizeForStorage(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = strtolower(trim($raw));
        if ($s === '' || $s === 'default') {
            return null;
        }
        if (in_array($s, self::STORAGE_SLUGS, true)) {
            return $s;
        }

        return null;
    }

    /** @return list<string> */
    public static function validationSlugs(): array
    {
        return array_merge(['default'], self::STORAGE_SLUGS);
    }

    public static function mapOverrideToDeductionScheduleType(string $override): string
    {
        return match (strtolower(trim($override))) {
            self::OVERRIDE_FIRST_RUN => DeductionScheduleSetting::SCHEDULE_15TH,
            self::OVERRIDE_SECOND_RUN => DeductionScheduleSetting::SCHEDULE_30TH,
            self::OVERRIDE_SPLIT, self::OVERRIDE_MONTHLY => DeductionScheduleSetting::SCHEDULE_BOTH,
            default => DeductionScheduleSetting::SCHEDULE_BOTH,
        };
    }

    /**
     * @param  string|null  $resolvedSchedule  Deduction schedule constant: 15th / 30th / both
     */
    public static function shortLabelForResolved(?string $resolvedSchedule): string
    {
        $t = strtolower(trim((string) $resolvedSchedule));
        if ($t === DeductionScheduleSetting::SCHEDULE_15TH) {
            return 'First semi-monthly run';
        }
        if ($t === DeductionScheduleSetting::SCHEDULE_30TH) {
            return 'End of month';
        }
        if ($t === DeductionScheduleSetting::SCHEDULE_BOTH) {
            return 'Split 15/30';
        }

        return $t !== '' ? $t : '—';
    }

    public static function shortLabelForStoredOverrideSlug(?string $slug): ?string
    {
        $s = strtolower(trim((string) $slug));
        if ($s === '') {
            return null;
        }
        if ($s === self::OVERRIDE_FIRST_RUN) {
            return '15th';
        }
        if ($s === self::OVERRIDE_SECOND_RUN) {
            return 'End of month';
        }
        if ($s === self::OVERRIDE_SPLIT) {
            return '15/30 Split';
        }
        if ($s === self::OVERRIDE_MONTHLY) {
            return 'Monthly / full run';
        }

        return null;
    }
}
