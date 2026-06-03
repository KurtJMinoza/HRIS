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
            if (! Schema::hasColumn('approval_workflow_settings', 'approval_chain_mode')) {
                $table->string('approval_chain_mode', 64)->default('custom_selected_steps')->after('fallback_to_parent_approver');
            }

            if (! Schema::hasColumn('approval_workflow_settings', 'max_org_approval_steps')) {
                $table->unsignedTinyInteger('max_org_approval_steps')->nullable()->after('approval_chain_mode');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        Schema::table('approval_workflow_settings', function (Blueprint $table): void {
            foreach (['max_org_approval_steps', 'approval_chain_mode'] as $column) {
                if (Schema::hasColumn('approval_workflow_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
