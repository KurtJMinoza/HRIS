<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_credit_logs') && ! Schema::hasTable('leave_credit_transactions')) {
            Schema::rename('leave_credit_logs', 'leave_credit_transactions');
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'leave_credits_reset_date')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('leave_credits_reset_date')->nullable()->after('leave_credits');
            });
        }

        if (Schema::hasColumn('users', 'leave_credits_year')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('UPDATE users SET leave_credits_reset_date = STR_TO_DATE(CONCAT(leave_credits_year, "-01-01"), "%Y-%m-%d") WHERE leave_credits_year IS NOT NULL AND leave_credits_reset_date IS NULL');
            } else {
                $rows = DB::table('users')->whereNotNull('leave_credits_year')->get(['id', 'leave_credits_year']);
                foreach ($rows as $row) {
                    $y = (int) $row->leave_credits_year;
                    if ($y > 0) {
                        DB::table('users')->where('id', $row->id)->update([
                            'leave_credits_reset_date' => sprintf('%04d-01-01', $y),
                        ]);
                    }
                }
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('leave_credits_year');
            });
        }

        $startOfYear = now()->startOfYear()->toDateString();
        DB::table('users')->whereNull('leave_credits_reset_date')->update([
            'leave_credits_reset_date' => $startOfYear,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'leave_credits_reset_date')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedSmallInteger('leave_credits_year')->nullable()->after('leave_credits');
            });

            $rows = DB::table('users')->whereNotNull('leave_credits_reset_date')->get(['id', 'leave_credits_reset_date']);
            foreach ($rows as $row) {
                $y = (int) substr((string) $row->leave_credits_reset_date, 0, 4);
                if ($y > 0) {
                    DB::table('users')->where('id', $row->id)->update(['leave_credits_year' => $y]);
                }
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('leave_credits_reset_date');
            });
        }

        if (Schema::hasTable('leave_credit_transactions') && ! Schema::hasTable('leave_credit_logs')) {
            Schema::rename('leave_credit_transactions', 'leave_credit_logs');
        }
    }
};
