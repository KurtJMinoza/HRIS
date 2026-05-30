<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'payroll_effective_date')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->date('payroll_effective_date')->nullable()->after('hire_date');
            });
        }

        DB::table('users')
            ->whereNull('payroll_effective_date')
            ->whereNotNull('created_at')
            ->update(['payroll_effective_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'payroll_effective_date')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('payroll_effective_date');
            });
        }
    }
};
