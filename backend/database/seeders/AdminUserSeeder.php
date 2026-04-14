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
                'name' => 'HRIS Admin',
                'password' => Hash::make('admin'), // change in production
                'role' => User::ROLE_ADMIN,
                'is_super_admin' => true,
            ]
        );
        User::where('email', 'admin@amalgated.co')->update(['is_super_admin' => true]);
    }
}
