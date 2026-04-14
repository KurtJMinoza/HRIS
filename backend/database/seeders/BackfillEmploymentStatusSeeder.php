<?php

namespace Database\Seeders;

use App\Enums\EmploymentStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class BackfillEmploymentStatusSeeder extends Seeder
{
    /**
     * Backfill employment_status for existing employees.
     * Maps employment_type to employment_status where possible.
     */
    public function run(): void
    {
        $employees = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->whereNull('employment_status')
            ->orWhere('employment_status', '')
            ->get();

        foreach ($employees as $employee) {
            $status = $this->inferStatus($employee);
            $employee->update(['employment_status' => $status->value]);
        }

        $this->command->info("Backfilled employment_status for {$employees->count()} employees.");
    }

    private function inferStatus(User $employee): EmploymentStatus
    {
        $type = strtolower(trim((string) ($employee->employment_type ?? '')));

        return match (true) {
            str_contains($type, 'regular') => EmploymentStatus::Regular,
            str_contains($type, 'probation') => EmploymentStatus::Probationary,
            str_contains($type, 'contract') => EmploymentStatus::Contractual,
            str_contains($type, 'project') => EmploymentStatus::ProjectBased,
            ! $employee->is_active => EmploymentStatus::Separated,
            default => EmploymentStatus::Probationary,
        };
    }
}
