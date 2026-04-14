<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_admin_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64)->index();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        $rows = [
            ['slug' => 'users.view', 'module' => 'users', 'label' => 'View users', 'description' => 'List and search user accounts'],
            ['slug' => 'users.manage', 'module' => 'users', 'label' => 'Manage users', 'description' => 'Create, edit, assign roles, activate/deactivate, reset passwords'],
        ];

        foreach ($rows as $row) {
            Permission::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'module' => $row['module'],
                    'label' => $row['label'],
                    'description' => $row['description'],
                ]
            );
        }

        $adminHr = Permission::query()->whereIn('slug', ['users.view', 'users.manage'])->pluck('id');
        foreach ($adminHr as $pid) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_key' => 'admin_hr',
                'permission_id' => $pid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_admin_activity_logs');

        $ids = Permission::query()->whereIn('slug', ['users.view', 'users.manage'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('slug', ['users.view', 'users.manage'])->delete();
    }
};
