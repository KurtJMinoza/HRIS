<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'contract_start_date')) {
                $table->date('contract_start_date')->nullable()->after('hire_date');
            }
            if (! Schema::hasColumn('users', 'contract_end_date')) {
                $table->date('contract_end_date')->nullable()->after('contract_start_date');
            }
            if (! Schema::hasColumn('users', 'employment_status_effective_date')) {
                $table->date('employment_status_effective_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'contract_start_date')) {
                $table->dropColumn('contract_start_date');
            }
            if (Schema::hasColumn('users', 'contract_end_date')) {
                $table->dropColumn('contract_end_date');
            }
            if (Schema::hasColumn('users', 'employment_status_effective_date')) {
                $table->dropColumn('employment_status_effective_date');
            }
        });
    }
};
