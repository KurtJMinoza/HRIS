<?php

use App\Enums\HrRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_auto_approve_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'user_id']);
        });

        $now = now();
        $slug = 'overtime.override.manage';

        DB::table('permissions')->updateOrInsert(
            ['slug' => $slug],
            [
                'module' => 'overtime',
                'label' => 'Manage OT auto-approve',
                'description' => 'Configure per-employee overtime auto-approve overrides',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_key' => HrRole::AdminHr->value,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $slug = 'overtime.override.manage';
        $id = DB::table('permissions')->where('slug', $slug)->value('id');
        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        Schema::dropIfExists('overtime_auto_approve_overrides');
    }
};
