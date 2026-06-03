<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('areas')) {
            Schema::create('areas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('area_name');
                $table->string('area_code')->nullable();
                $table->foreignId('area_manager_employee_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('description')->nullable();
                $table->string('status', 32)->default('active')->index();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'area_code'], 'areas_company_code_unique');
                $table->index(['company_id', 'status'], 'areas_company_status_index');
                $table->index(['area_manager_employee_id', 'status'], 'areas_manager_status_index');
                $table->index(['effective_from', 'effective_to'], 'areas_effective_window_index');
            });
        }

        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'area_id')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->foreignId('area_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('areas')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('organization_types')) {
            DB::table('organization_types')->updateOrInsert(
                ['code' => 'area'],
                [
                    'name' => 'Area',
                    'level_order' => 15,
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('organization_position_types')) {
            DB::table('organization_position_types')->updateOrInsert(
                ['organization_level' => 'area', 'position_name' => 'Area Head / Area Manager'],
                [
                    'approval_priority' => 1,
                    'can_approve' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'area_id')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('area_id');
            });
        }

        Schema::dropIfExists('areas');

        if (Schema::hasTable('organization_types')) {
            DB::table('organization_types')->where('code', 'area')->delete();
        }

        if (Schema::hasTable('organization_position_types')) {
            DB::table('organization_position_types')
                ->where('organization_level', 'area')
                ->delete();
        }
    }
};
