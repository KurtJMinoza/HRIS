<?php

use App\Models\PayComponent;
use App\Services\PayComponentService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_components')) {
            return;
        }

        // Legacy installs may use ENUM or VARCHAR shorter than `percent_basic` / `percent_gross`, which
        // triggers MySQL 1265 "Data truncated" when seeding system components (e.g. THIRTEENTH_MONTH).
        if (Schema::hasColumn('pay_components', 'calculation_type')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `pay_components` MODIFY `calculation_type` VARCHAR(64) NOT NULL');
            }
        }

        Schema::table('pay_components', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_components', 'component_type')) {
                $table->string('component_type', 16)->default(PayComponent::COMPONENT_USER)->after('apply_to_all');
            }
            if (! Schema::hasColumn('pay_components', 'is_system_protected')) {
                $table->boolean('is_system_protected')->default(false)->after('component_type');
            }
        });

        /** @var PayComponentService $service */
        $service = app(PayComponentService::class);
        foreach ($service->systemProtectedBlueprints() as $row) {
            // Use query builder here (not Eloquent) so this migration works even before
            // `deleted_at` exists on legacy databases with SoftDeletes global scope enabled.
            DB::table('pay_components')->updateOrInsert(
                ['code' => strtoupper((string) $row['code'])],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'category' => $row['category'],
                    'calculation_type' => $row['calculation_type'],
                    'default_value' => $row['default_value'],
                    'formula' => $row['formula'] ?? null,
                    'is_taxable' => $row['is_taxable'],
                    'contributes_sss' => $row['contributes_sss'],
                    'contributes_philhealth' => $row['contributes_philhealth'],
                    'contributes_pagibig' => $row['contributes_pagibig'],
                    'is_proratable' => $row['is_proratable'],
                    'apply_to_all' => false,
                    'component_type' => PayComponent::COMPONENT_SYSTEM,
                    'is_system_protected' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pay_components')) {
            return;
        }

        Schema::table('pay_components', function (Blueprint $table) {
            if (Schema::hasColumn('pay_components', 'is_system_protected')) {
                $table->dropColumn('is_system_protected');
            }
            if (Schema::hasColumn('pay_components', 'component_type')) {
                $table->dropColumn('component_type');
            }
        });
    }
};
