<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'is_execom')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_execom')->default(false)->after('exclude_from_payroll');
            $table->index(['is_execom'], 'users_is_execom_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'is_execom')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            try {
                $table->dropIndex('users_is_execom_idx');
            } catch (Throwable) {
                // Guarded for partially migrated local databases.
            }
            $table->dropColumn('is_execom');
        });
    }
};
