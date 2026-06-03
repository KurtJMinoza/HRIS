<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        Schema::table('approval_workflow_settings', function (Blueprint $table): void {
            foreach ([
                'include_section_head',
                'include_department_head',
                'include_division_head',
                'include_branch_head',
                'include_area_head',
                'include_company_head',
                'include_admin_hr',
            ] as $column) {
                if (! Schema::hasColumn('approval_workflow_settings', $column)) {
                    $table->boolean($column)->default(true);
                }
            }
        });

        DB::table('approval_workflow_settings')
            ->whereIn('request_type', ['leave', 'overtime'])
            ->update([
                'use_hierarchy_approval' => true,
                'fallback_to_parent_approver' => true,
                'include_section_head' => true,
                'include_department_head' => true,
                'include_division_head' => true,
                'include_branch_head' => true,
                'include_area_head' => true,
                'include_company_head' => true,
                'include_admin_hr' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        Schema::table('approval_workflow_settings', function (Blueprint $table): void {
            foreach ([
                'include_section_head',
                'include_department_head',
                'include_division_head',
                'include_branch_head',
                'include_area_head',
                'include_company_head',
                'include_admin_hr',
            ] as $column) {
                if (Schema::hasColumn('approval_workflow_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
