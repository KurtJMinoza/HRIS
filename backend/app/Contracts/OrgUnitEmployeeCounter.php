<?php

namespace App\Contracts;

use App\Models\Division;
use App\Models\SectionUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

interface OrgUnitEmployeeCounter
{
    /**
     * @return array{
     *   assigned_employee_count: int,
     *   branch_employee_count: int,
     *   unassigned_employee_count: int,
     *   total_employees: int
     * }
     */
    public function forDivision(Division $division): array;

    /**
     * @param  Collection<int, Division>  $divisions
     * @return array<int, array{
     *   assigned_employee_count: int,
     *   branch_employee_count: int,
     *   unassigned_employee_count: int,
     *   total_employees: int
     * }>
     */
    public function forDivisions(Collection $divisions): array;

    /**
     * Roster employees assigned to a division (direct division_id, or via child departments/sections).
     *
     * @return Builder<User>
     */
    public function divisionMembersQuery(int $divisionId): Builder;

    /**
     * @return array{
     *   assigned_employee_count: int,
     *   department_employee_count: int,
     *   unassigned_employee_count: int,
     *   total_employees: int
     * }
     */
    public function forSectionUnit(SectionUnit $section): array;

    /**
     * @param  Collection<int, SectionUnit>  $sections
     * @return array<int, array{
     *   assigned_employee_count: int,
     *   department_employee_count: int,
     *   unassigned_employee_count: int,
     *   total_employees: int
     * }>
     */
    public function forSectionUnits(Collection $sections): array;
}
