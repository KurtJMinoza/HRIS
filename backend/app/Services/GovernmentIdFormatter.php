<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Canonical PH government-ID formats for SSS, PhilHealth, Pag-IBIG, TIN.
 *
 * Mirrors the per-type masks defined in the frontend (`EmployeeProfile.jsx`
 * -> `govIdDefs`) so manual entry, admin uploads, and XLSX imports always
 * store the same dashed canonical form — e.g. "12-3456789-0" for SSS or
 * "123-456-789-000" for TIN. Imports call {@see self::format()} to
 * canonicalize any raw value (digits, with/without dashes) before persist.
 */
class GovernmentIdFormatter
{
    public const TYPE_SSS = 'SSS ID / UMID';

    public const TYPE_PHILHEALTH = 'PhilHealth ID';

    public const TYPE_PAGIBIG = 'Pag-IBIG ID (HDMF)';

    public const TYPE_TIN = 'TIN ID';

    public const AGENCY_SSS = 'Social Security System';

    public const AGENCY_PHILHEALTH = 'PhilHealth';

    public const AGENCY_PAGIBIG = 'Pag-IBIG Fund';

    public const AGENCY_TIN = 'BIR';

    /**
     * Map any synonym or free-text label (case / spacing / dash variants) to one
     * of the four canonical constants above. Returns null when nothing matches.
     */
    public static function canonicalType(?string $type): ?string
    {
        $raw = trim((string) $type);
        if ($raw === '') {
            return null;
        }

        $compact = Str::lower(preg_replace('/[^a-z0-9]+/i', '', $raw) ?? '');
        if ($compact === '') {
            return null;
        }

        return match (true) {
            str_contains($compact, 'philhealth') || $compact === 'phic' => self::TYPE_PHILHEALTH,
            str_contains($compact, 'pagibig') || str_contains($compact, 'hdmf') => self::TYPE_PAGIBIG,
            str_contains($compact, 'sss') || str_contains($compact, 'umid') => self::TYPE_SSS,
            str_contains($compact, 'tin') || str_contains($compact, 'bir') => self::TYPE_TIN,
            default => null,
        };
    }

    public static function agencyFor(?string $type): ?string
    {
        return match (self::canonicalType($type)) {
            self::TYPE_SSS => self::AGENCY_SSS,
            self::TYPE_PHILHEALTH => self::AGENCY_PHILHEALTH,
            self::TYPE_PAGIBIG => self::AGENCY_PAGIBIG,
            self::TYPE_TIN => self::AGENCY_TIN,
            default => null,
        };
    }

    /**
     * Format a raw ID value (may contain dashes, letters, spaces) into the canonical
     * dashed form for the given type. Returns null when the digit count does not
     * match any supported pattern (so callers can skip storing gibberish).
     *
     * SSS        10 digits -> XX-XXXXXXX-X
     * PhilHealth 12 digits -> XX-XXXXXXXXX-X
     * Pag-IBIG   12 digits -> XXXX-XXXX-XXXX
     * TIN        9 digits  -> XXX-XXX-XXX
     * TIN        12 digits -> XXX-XXX-XXX-XXX  (with branch code)
     */
    public static function format(?string $type, mixed $raw): ?string
    {
        $canon = self::canonicalType($type);
        if ($canon === null || $raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        return match ($canon) {
            self::TYPE_SSS => strlen($digits) === 10
                ? substr($digits, 0, 2).'-'.substr($digits, 2, 7).'-'.substr($digits, 9, 1)
                : null,
            self::TYPE_PHILHEALTH => strlen($digits) === 12
                ? substr($digits, 0, 2).'-'.substr($digits, 2, 9).'-'.substr($digits, 11, 1)
                : null,
            self::TYPE_PAGIBIG => strlen($digits) === 12
                ? substr($digits, 0, 4).'-'.substr($digits, 4, 4).'-'.substr($digits, 8, 4)
                : null,
            self::TYPE_TIN => match (strlen($digits)) {
                12 => substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3).'-'.substr($digits, 9, 3),
                9 => substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3),
                default => null,
            },
            default => null,
        };
    }

    public static function isValidFormatted(?string $type, ?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return match (self::canonicalType($type)) {
            self::TYPE_SSS => (bool) preg_match('/^\d{2}-\d{7}-\d$/', $value),
            self::TYPE_PHILHEALTH => (bool) preg_match('/^\d{2}-\d{9}-\d$/', $value),
            self::TYPE_PAGIBIG => (bool) preg_match('/^\d{4}-\d{4}-\d{4}$/', $value),
            // Accept both 9-digit (no branch) and 12-digit (with branch) TIN.
            self::TYPE_TIN => (bool) preg_match('/^\d{3}-\d{3}-\d{3}(-\d{3})?$/', $value),
            default => false,
        };
    }

    /**
     * Human readable format hint (same wording as the frontend validation error).
     */
    public static function formatHint(?string $type): ?string
    {
        return match (self::canonicalType($type)) {
            self::TYPE_SSS => 'SSS format must be 00-0000000-0 (10 digits).',
            self::TYPE_PHILHEALTH => 'PhilHealth format must be 00-000000000-0 (12 digits).',
            self::TYPE_PAGIBIG => 'Pag-IBIG format must be 0000-0000-0000 (12 digits).',
            self::TYPE_TIN => 'TIN format must be 000-000-000 or 000-000-000-000.',
            default => null,
        };
    }
}
