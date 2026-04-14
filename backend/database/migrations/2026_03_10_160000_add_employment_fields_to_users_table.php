<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'employee_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('employee_code')->nullable()->unique()->after('id');
            });
        }
        if (! Schema::hasColumn('users', 'branch_office_location')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('branch_office_location')->nullable()->after('position');
            });
        }
        if (! Schema::hasColumn('users', 'employment_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('employment_type')->nullable()->after('branch_office_location');
            });
        }
        if (! Schema::hasColumn('users', 'hire_date')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('hire_date')->nullable()->after('employment_type');
            });
        }
        if (! Schema::hasColumn('users', 'supervisor_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('supervisor_id')->nullable()->after('hire_date')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'supervisor_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('supervisor_id');
            });
        }
        $columns = [
            'employee_code',
            'branch_office_location',
            'employment_type',
            'hire_date',
        ];
        $existing = array_values(array_filter($columns, fn (string $col) => Schema::hasColumn('users', $col)));
        if ($existing !== []) {
            Schema::table('users', function (Blueprint $table) use ($existing) {
                $table->dropColumn($existing);
            });
        }
    }
};
