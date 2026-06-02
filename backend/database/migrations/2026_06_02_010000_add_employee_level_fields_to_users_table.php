<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'employee_level')) {
                $table->unsignedTinyInteger('employee_level')->nullable()->after('role')->index();
            }
            if (! Schema::hasColumn('users', 'employee_level_label')) {
                $table->string('employee_level_label')->nullable()->after('employee_level');
            }
            if (! Schema::hasColumn('users', 'employee_level_resolved_at')) {
                $table->timestamp('employee_level_resolved_at')->nullable()->after('employee_level_label');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'employee_level_resolved_at')) {
                $table->dropColumn('employee_level_resolved_at');
            }
            if (Schema::hasColumn('users', 'employee_level_label')) {
                $table->dropColumn('employee_level_label');
            }
            if (Schema::hasColumn('users', 'employee_level')) {
                $table->dropColumn('employee_level');
            }
        });
    }
};
