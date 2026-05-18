<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable()->after('is_active')->index('users_deactivated_at_idx');
            }
            if (! Schema::hasColumn('users', 'deactivated_by')) {
                $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'deactivation_reason')) {
                $table->string('deactivation_reason', 500)->nullable()->after('deactivated_by');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (! $this->indexExists('users', 'users_active_employment_idx')) {
                $table->index(['is_active', 'employment_status'], 'users_active_employment_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if ($this->indexExists('users', 'users_active_employment_idx')) {
                $table->dropIndex('users_active_employment_idx');
            }
            if (Schema::hasColumn('users', 'deactivated_by')) {
                $table->dropConstrainedForeignId('deactivated_by');
            }
            foreach (['deactivation_reason', 'deactivated_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $dbName = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
