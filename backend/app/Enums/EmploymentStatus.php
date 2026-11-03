<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Probationary = 'probationary';
    case Regular = 'regular';
    case Contractual = 'contractual';
    case ProjectBased = 'project_based';
    case Consultant = 'consultant';
    case ConsultantRegular = 'consultant_regular';
    case ConsultantProbationary = 'consultant_probationary';
    case Separated = 'separated';

    public function label(): string
    {
        return match ($this) {
            self::Probationary => 'Probationary',
            self::Regular => 'Regular',
            self::Contractual => 'Contractual',
            self::ProjectBased => 'Project-based',
            self::Consultant => 'Consultant',
            self::ConsultantRegular => 'Consultant – Regular',
            self::ConsultantProbationary => 'Consultant – Probationary',
            self::Separated => 'Separated',
        };
    }

    public function canBeRegularized(): bool
    {
        return $this === self::Probationary || $this === self::ConsultantProbationary;
    }

    public function isActive(): bool
    {
        return $this !== self::Separated;
    }

    /** Runs in the Consultant Payroll module (never in the regular payroll run). */
    public function isConsultantPayrollModule(): bool
    {
        return in_array($this, [self::Consultant, self::ConsultantRegular, self::ConsultantProbationary], true);
    }

    /**
     * Status whose payroll, leave, and holiday rules apply. Consultant – Regular / – Probationary
     * compute exactly like Regular / Probationary; only their payroll module differs.
     */
    public function payrollBasis(): self
    {
        return match ($this) {
            self::ConsultantRegular => self::Regular,
            self::ConsultantProbationary => self::Probationary,
            default => $this,
        };
    }

    /** Status assigned when this probationary status is regularized. */
    public function regularizedStatus(): self
    {
        return $this === self::ConsultantProbationary ? self::ConsultantRegular : self::Regular;
    }

    /** @return list<string> Stored values routed to the Consultant Payroll module. */
    public static function consultantPayrollModuleValues(): array
    {
        return [self::Consultant->value, self::ConsultantRegular->value, self::ConsultantProbationary->value];
    }

    /** @return list<string> Stored values that follow the probationary regularization flow. */
    public static function regularizableValues(): array
    {
        return [self::Probationary->value, self::ConsultantProbationary->value];
    }

    public static function default(): self
    {
        return self::Probationary;
    }

    /**
     * Canonical display label for non–Admin (HR) viewers (org heads, employees).
     * Maps legacy/alias raw values to the standard options.
     */
    public static function normalizeToCanonicalLabel(?string $raw): ?string
    {
        return self::tryFromStored($raw)?->label();
    }

    /**
     * Parse `users.employment_status` for business rules (leave credits, payroll).
     * Handles legacy casing ("Regular"), spacing, and aliases ("active" → Regular).
     */
    public static function tryFromStored(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $s = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $raw))), '_');

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
            'consultant_regular' => self::ConsultantRegular,
            'regular_consultant' => self::ConsultantRegular,
            'consultant_probationary' => self::ConsultantProbationary,
            'consultant_probation' => self::ConsultantProbationary,
            'probationary_consultant' => self::ConsultantProbationary,
            'separated' => self::Separated,
            'inactive' => self::Separated,
            'resigned' => self::Separated,
            'terminated' => self::Separated,
        ];

        return $aliases[$s] ?? self::tryFrom($s);
    }
}
