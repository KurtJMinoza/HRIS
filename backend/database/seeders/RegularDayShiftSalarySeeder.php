<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Assign random monthly salary for employees under "Regular Day Shift".
 *
 * Rules:
 * - Target users with role=employee
 * - Must have working schedule template named "Regular Day Shift" (case-insensitive)
 * - Salary range: 17,000 to 25,000
 */
class RegularDayShiftSalarySeeder extends Seeder
{
    public function run(): void
    {
        $today = now()->toDateString();

        User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->whereHas('workingSchedule', function ($q) {
                $q->whereRaw('LOWER(name) = ?', ['regular day shift']);
            })
            ->select('id', 'monthly_salary', 'monthly_rate', 'salary_effectivity_date')
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($today) {
                foreach ($users as $user) {
                    $salary = (float) random_int(17000, 25000);

                    // Keep salary fields aligned for payroll/profile displays.
                    $user->forceFill([
                        'monthly_salary' => $salary,
                        'monthly_rate' => $salary,
                        'salary_effectivity_date' => $user->salary_effectivity_date ?: $today,
                    ])->save();
                }
            });
    }
}

