<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('department_id')->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('branch_id')->constrained('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['team_id']);
        });
    }
};
