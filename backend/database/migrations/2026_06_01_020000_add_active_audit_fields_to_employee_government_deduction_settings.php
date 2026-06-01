<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_government_deduction_settings')) {
            return;
        }

        Schema::table('employee_government_deduction_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_government_deduction_settings', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('exemption_reason');
            }
            if (! Schema::hasColumn('employee_government_deduction_settings', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('employee_government_deduction_settings', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_government_deduction_settings')) {
            return;
        }

        Schema::table('employee_government_deduction_settings', function (Blueprint $table) {
            if (Schema::hasColumn('employee_government_deduction_settings', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }
            if (Schema::hasColumn('employee_government_deduction_settings', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            if (Schema::hasColumn('employee_government_deduction_settings', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
