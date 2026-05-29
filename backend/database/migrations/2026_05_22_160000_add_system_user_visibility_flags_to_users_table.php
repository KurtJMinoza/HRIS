<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_system_user')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('is_system_user')->default(false)->after('is_super_admin')->index();
            });
        }

        if (! Schema::hasColumn('users', 'is_hidden')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('is_hidden')->default(false)->after('is_system_user')->index();
            });
        }

        if (! Schema::hasColumn('users', 'exclude_from_reports')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('exclude_from_reports')->default(false)->after('is_hidden')->index();
            });
        }

        if (! Schema::hasColumn('users', 'exclude_from_payroll')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('exclude_from_payroll')->default(false)->after('exclude_from_reports')->index();
            });
        }

        if (! Schema::hasColumn('users', 'exclude_from_attendance')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('exclude_from_attendance')->default(false)->after('exclude_from_payroll')->index();
            });
        }

        if (! Schema::hasColumn('users', 'exclude_from_approvals')) {
            Schema::table('users', function (Blueprint $table): void {
                $afterColumn = Schema::hasColumn('users', 'exclude_from_attendance')
                    ? 'exclude_from_attendance'
                    : (Schema::hasColumn('users', 'exclude_from_payroll') ? 'exclude_from_payroll' : null);

                if ($afterColumn !== null) {
                    $table->boolean('exclude_from_approvals')->default(false)->after($afterColumn)->index();
                } else {
                    $table->boolean('exclude_from_approvals')->default(false)->index();
                }
            });
        }

        if (Schema::hasTable('user_admin_activity_logs') && ! Schema::hasColumn('user_admin_activity_logs', 'actor_role')) {
            Schema::table('user_admin_activity_logs', function (Blueprint $table): void {
                $table->string('actor_role', 64)->nullable()->after('actor_user_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_admin_activity_logs') && Schema::hasColumn('user_admin_activity_logs', 'actor_role')) {
            Schema::table('user_admin_activity_logs', function (Blueprint $table): void {
                $table->dropColumn('actor_role');
            });
        }

        $columns = array_values(array_filter([
            'exclude_from_approvals',
            'exclude_from_attendance',
            'exclude_from_payroll',
            'exclude_from_reports',
            'is_hidden',
            'is_system_user',
        ], fn (string $column): bool => Schema::hasColumn('users', $column)));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
