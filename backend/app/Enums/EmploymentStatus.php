<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Probationary = 'probationary';
    case Regular = 'regular';
    case Contractual = 'contractual';
    case ProjectBased = 'project_based';
    case Consultant = 'consultant';
    case Separated = 'separated';

    public function label(): string
    {
        return match ($this) {
            self::Probationary => 'Probationary',
            self::Regular => 'Regular',
            self::Contractual => 'Contractual',
            self::ProjectBased => 'Project-based',
            self::Consultant => 'Consultant',
            self::Separated => 'Separated',
        };
    }

    public function canBeRegularized(): bool
    {
        return $this === self::Probationary;
    }

    public function isActive(): bool
    {
        return $this !== self::Separated;
    }

    public static function default(): self
    {
        return self::Probationary;
    }

    /**
     * Canonical display label for non–Admin (HR) viewers (org heads, employees).
     * Maps legacy/alias raw values to the five standard options.
     */
    public static function normalizeToCanonicalLabel(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $s = strtolower(str_replace(['-', ' '], '_', trim((string) $raw)));

        $aliases = [
            'probation' => self::Probationary,
            'probational' => self::Probationary,
            'probationary' => self::Probationary,
            'permanent' => self::Regular,
            'regular' => self::Regular,
            'active' => self::Regular,
            'contract' => self::Contractual,
            'contractual' => self::Contractual,
            'project' => self::ProjectBased,
            'project_based' => self::ProjectBased,
            'projectbased' => self::ProjectBased,
            'consultant' => self::Consultant,
            'consultancy' => self::Consultant,
            'separated' => self::Separated,
            'inactive' => self::Separated,
            'resigned' => self::Separated,
            'terminated' => self::Separated,
        ];

        if (isset($aliases[$s])) {
            return $aliases[$s]->label();
        }

        return self::tryFrom($s)?->label();
    }

    /**
     * Parse `users.employment_status` for business rules (leave credits, payroll).
     * Handles legacy casing ("Regular"), spacing, and aliases ("active" → Regular) — same mapping as
     * {@see normalizeToCanonicalLabel()} but returns the enum case.
     */
    public static function tryFromStored(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $s = strtolower(str_replace(['-', ' '], '_', trim((string) $raw)));

        $aliases = [
            'probation' => self::Probationary,
            'probational' => self::Probationary,
            'probationary' => self::Probationary,
            'permanent' => self::Regular,
            'regular' => self::Regular,
            'active' => self::Regular,
            'contract' => self::Contractual,
            'contractual' => self::Contractual,
            'project' => self::ProjectBased,
            'project_based' => self::ProjectBased,
            'projectbased' => self::ProjectBased,
            'consultant' => self::Consultant,
            'consultancy' => self::Consultant,
            'separated' => self::Separated,
            'inactive' => self::Separated,
            'resigned' => self::Separated,
            'terminated' => self::Separated,
        ];

        if (isset($aliases[$s])) {
            return $aliases[$s];
        }

        return self::tryFrom($s);
    }
}
