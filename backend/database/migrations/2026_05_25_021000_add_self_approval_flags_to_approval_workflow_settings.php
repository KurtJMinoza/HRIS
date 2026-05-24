<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        Schema::table('approval_workflow_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('approval_workflow_settings', 'allow_admin_self_approval')) {
                $table->boolean('allow_admin_self_approval')->default(true)->after('fallback_to_parent_approver');
            }
            if (! Schema::hasColumn('approval_workflow_settings', 'allow_hr_self_approval')) {
                $table->boolean('allow_hr_self_approval')->default(true)->after('allow_admin_self_approval');
            }
            if (! Schema::hasColumn('approval_workflow_settings', 'allow_super_admin_self_approval')) {
                $table->boolean('allow_super_admin_self_approval')->default(true)->after('allow_hr_self_approval');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        Schema::table('approval_workflow_settings', function (Blueprint $table): void {
            foreach ([
                'allow_super_admin_self_approval',
                'allow_hr_self_approval',
                'allow_admin_self_approval',
            ] as $column) {
                if (Schema::hasColumn('approval_workflow_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
