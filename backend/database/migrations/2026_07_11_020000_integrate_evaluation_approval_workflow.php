<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            if (! Schema::hasColumn('evaluations', 'pending_approval')) {
                $table->boolean('pending_approval')->default(false)->after('status');
            }
            if (! Schema::hasColumn('evaluations', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('reviewer_remarks');
            }
            if (! Schema::hasColumn('evaluations', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('evaluations', 'rejection_note')) {
                $table->text('rejection_note')->nullable()->after('rejected_by');
            }
        });

        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        $now = now();
        $legacy = Schema::hasTable('evaluation_workflow_settings')
            ? DB::table('evaluation_workflow_settings')
                ->where('request_type', 'evaluation')
                ->first()
            : null;

        $exists = DB::table('approval_workflow_settings')->where('request_type', 'evaluation')->exists();
        if ($exists) {
            return;
        }

        DB::table('approval_workflow_settings')->insert([
            'request_type' => 'evaluation',
            'use_hierarchy_approval' => (bool) ($legacy->use_hierarchy_approval ?? true),
            'final_approver_role' => $legacy->final_approver_role ?? 'admin_hr',
            'require_final_hr_approval' => (bool) ($legacy->require_final_hr_approval ?? true),
            'immediate_approver_mode' => $legacy->immediate_approver_mode ?? 'nearest_leader',
            'fallback_to_hr' => (bool) ($legacy->fallback_to_hr ?? true),
            'fallback_to_parent_approver' => (bool) ($legacy->fallback_to_parent_approver ?? false),
            'approval_chain_mode' => $legacy->approval_chain_mode ?? 'custom_selected_steps',
            'max_org_approval_steps' => $legacy->max_org_approval_steps ?? null,
            'include_section_head' => (bool) ($legacy->include_section_head ?? true),
            'include_department_head' => (bool) ($legacy->include_department_head ?? true),
            'include_division_head' => (bool) ($legacy->include_division_head ?? true),
            'include_branch_head' => (bool) ($legacy->include_branch_head ?? true),
            'include_area_head' => (bool) ($legacy->include_area_head ?? true),
            'include_company_head' => (bool) ($legacy->include_company_head ?? true),
            'include_admin_hr' => (bool) ($legacy->include_admin_hr ?? true),
            'allow_admin_self_approval' => (bool) ($legacy->allow_admin_self_approval ?? true),
            'allow_hr_self_approval' => (bool) ($legacy->allow_hr_self_approval ?? true),
            'allow_super_admin_self_approval' => (bool) ($legacy->allow_super_admin_self_approval ?? true),
            'is_active' => (bool) ($legacy->is_active ?? true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('approval_workflow_settings')) {
            DB::table('approval_workflow_settings')->where('request_type', 'evaluation')->delete();
        }

        Schema::table('evaluations', function (Blueprint $table) {
            if (Schema::hasColumn('evaluations', 'rejection_note')) {
                $table->dropColumn('rejection_note');
            }
            if (Schema::hasColumn('evaluations', 'rejected_by')) {
                $table->dropConstrainedForeignId('rejected_by');
            }
            if (Schema::hasColumn('evaluations', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }
            if (Schema::hasColumn('evaluations', 'pending_approval')) {
                $table->dropColumn('pending_approval');
            }
        });
    }
};
