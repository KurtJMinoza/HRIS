<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_workflow_settings', function (Blueprint $table) {
            $table->id();
            $table->string('request_type', 64)->unique();
            $table->boolean('use_hierarchy_approval')->default(false);
            $table->string('final_approver_role', 64)->default('admin_hr');
            $table->boolean('require_final_hr_approval')->default(true);
            $table->string('immediate_approver_mode', 64)->default('nearest_leader');
            $table->boolean('fallback_to_hr')->default(true);
            $table->boolean('fallback_to_parent_approver')->default(false);
            $table->string('approval_chain_mode', 64)->default('custom_selected_steps');
            $table->integer('max_org_approval_steps')->nullable();
            $table->boolean('include_section_head')->default(false);
            $table->boolean('include_department_head')->default(false);
            $table->boolean('include_division_head')->default(false);
            $table->boolean('include_branch_head')->default(false);
            $table->boolean('include_area_head')->default(false);
            $table->boolean('include_company_head')->default(false);
            $table->boolean('include_admin_hr')->default(true);
            $table->boolean('allow_admin_self_approval')->default(true);
            $table->boolean('allow_hr_self_approval')->default(true);
            $table->boolean('allow_super_admin_self_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('evaluation_workflow_settings')->insert([
            'request_type' => 'evaluation',
            'use_hierarchy_approval' => true,
            'final_approver_role' => 'admin_hr',
            'require_final_hr_approval' => true,
            'immediate_approver_mode' => 'nearest_leader',
            'fallback_to_hr' => true,
            'fallback_to_parent_approver' => false,
            'approval_chain_mode' => 'custom_selected_steps',
            'max_org_approval_steps' => null,
            'include_section_head' => true,
            'include_department_head' => true,
            'include_division_head' => true,
            'include_branch_head' => true,
            'include_area_head' => true,
            'include_company_head' => true,
            'include_admin_hr' => true,
            'allow_admin_self_approval' => true,
            'allow_hr_self_approval' => true,
            'allow_super_admin_self_approval' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_workflow_settings');
    }
};
