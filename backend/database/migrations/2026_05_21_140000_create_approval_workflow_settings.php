<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflow_settings', function (Blueprint $table) {
            $table->id();
            $table->string('request_type', 64)->unique();
            $table->boolean('use_hierarchy_approval')->default(false);
            $table->string('final_approver_role', 64)->default('admin_hr');
            $table->boolean('require_final_hr_approval')->default(true);
            $table->string('immediate_approver_mode', 64)->default('nearest_leader');
            $table->boolean('fallback_to_hr')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            ['request_type' => 'attendance_correction', 'use_hierarchy_approval' => false],
            ['request_type' => 'leave', 'use_hierarchy_approval' => true],
            ['request_type' => 'overtime', 'use_hierarchy_approval' => true],
            ['request_type' => 'change_schedule', 'use_hierarchy_approval' => false],
            ['request_type' => 'reports_request', 'use_hierarchy_approval' => false],
        ];

        foreach ($defaults as $row) {
            DB::table('approval_workflow_settings')->insert([
                'request_type' => $row['request_type'],
                'use_hierarchy_approval' => $row['use_hierarchy_approval'],
                'final_approver_role' => 'admin_hr',
                'require_final_hr_approval' => true,
                'immediate_approver_mode' => 'nearest_leader',
                'fallback_to_hr' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_workflow_settings');
    }
};
