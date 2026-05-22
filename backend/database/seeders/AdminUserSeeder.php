<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the default admin user for HRIS.
     * Run: php artisan db:seed --class=AdminUserSeeder
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@amalgated.co'],
            [
                'name' => 'Super Admin',
                'username' => 'superadmin',
                'password' => Hash::make('aci12345'), // change in production
                'role' => User::ROLE_SUPER_ADMIN,
                'is_super_admin' => true,
                'is_system_user' => true,
                'is_hidden' => true,
                'exclude_from_reports' => true,
                'exclude_from_payroll' => true,
                'exclude_from_attendance' => true,
                'exclude_from_approvals' => true,
            ]
        );
        User::where('email', 'admin@amalgated.co')->update([
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'role' => User::ROLE_SUPER_ADMIN,
            'password' => Hash::make('aci12345'),
            'is_super_admin' => true,
            'is_system_user' => true,
            'is_hidden' => true,
            'exclude_from_reports' => true,
            'exclude_from_payroll' => true,
            'exclude_from_attendance' => true,
            'exclude_from_approvals' => true,
            'company_id' => null,
            'branch_id' => null,
            'department_id' => null,
            'division_id' => null,
            'section_unit_id' => null,
            'supervisor_id' => null,
            'assigned_team_leader_id' => null,
        ]);
    }
}
