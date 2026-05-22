<?php

namespace App\Enums;

/**
 * Resolved HR access role for RBAC (orthogonal to users.role admin|employee).
 * Priority when resolving from org assignments:
 * company_head > branch_head > department_head > section_unit_head > employee.
 */
enum HrRole: string
{
    case AdminHr = 'admin_hr';
    case CompanyHead = 'company_head';
    case BranchHead = 'branch_head';
    case DepartmentHead = 'department_head';
    case DivisionHead = 'division_head';
    case SectionUnitHead = 'section_unit_head';
    case Employee = 'employee';

    public function label(): string
    {
        return $this->badgeLabel();
    }

    /** Short label for UI badges (title case; used across API and frontend). */
    public function badgeLabel(): string
    {
        return match ($this) {
            self::AdminHr => 'Admin (HR)',
            self::CompanyHead => 'Company Head',
            self::BranchHead => 'Branch Head',
            self::DepartmentHead => 'Department Head',
            self::DivisionHead => 'Division Head',
            self::SectionUnitHead => 'Section/Unit Head',
            self::Employee => 'Employee',
        };
    }

    /** Whether this role may access the HR admin API surface (hr.panel middleware). */
    public function canAccessHrPanel(): bool
    {
        return $this !== self::Employee;
    }
}
