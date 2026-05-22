<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_position_assignments')) {
            return;
        }

        if (! Schema::hasColumn('organization_position_assignments', 'remarks')) {
            Schema::table('organization_position_assignments', function (Blueprint $table): void {
                $table->string('remarks', 500)->nullable()->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('organization_position_assignments')) {
            return;
        }

        if (Schema::hasColumn('organization_position_assignments', 'remarks')) {
            Schema::table('organization_position_assignments', function (Blueprint $table): void {
                $table->dropColumn('remarks');
            });
        }
    }
};
