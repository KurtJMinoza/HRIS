<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacy = Schema::hasTable('thirteenth_month_settings')
            ? DB::table('thirteenth_month_settings')->orderByDesc('updated_at')->first()
            : null;

        Schema::dropIfExists('thirteenth_month_payslip_inclusions');
        Schema::dropIfExists('thirteenth_month_employee_results');
        Schema::dropIfExists('thirteenth_month_configurations');
        Schema::dropIfExists('thirteenth_month_settings');

        Schema::create('thirteenth_month_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_scope_type', 16)->default('all');
            $table->json('company_ids')->nullable();
            $table->string('basis_type', 16)->default('basic');
            $table->string('coverage_type', 32)->default('dec_nov');
            $table->unsignedTinyInteger('coverage_start_month');
            $table->unsignedSmallInteger('coverage_start_year');
            $table->unsignedTinyInteger('coverage_end_month');
            $table->unsignedSmallInteger('coverage_end_year');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('is_active');
        });

        if ($legacy) {
            $year = (int) now()->year;
            $type = (string) ($legacy->default_coverage_type ?? 'fiscal_year');
            [$sm, $sy, $em, $ey] = match ($type) {
                'calendar_year' => [1, $year, 12, $year],
                'first_half' => [1, $year, 6, $year],
                'second_half' => [7, $year, 12, $year],
                'custom' => [
                    (int) ($legacy->custom_start_month ?? 1),
                    (int) ($legacy->custom_start_year ?? $year),
                    (int) ($legacy->custom_end_month ?? 12),
                    (int) ($legacy->custom_end_year ?? $year),
                ],
                default => [12, $year - 1, 11, $year],
            };
            DB::table('thirteenth_month_settings')->insert([
                'company_scope_type' => 'specific',
                'company_ids' => json_encode([(int) $legacy->company_id]),
                'basis_type' => ($legacy->basis_type ?? 'basic_pay') === 'gross_pay' ? 'gross' : 'basic',
                'coverage_type' => $type === 'fiscal_year' ? 'dec_nov' : $type,
                'coverage_start_month' => $sm,
                'coverage_start_year' => $sy,
                'coverage_end_month' => $em,
                'coverage_end_year' => $ey,
                'is_active' => true,
                'updated_by' => $legacy->updated_by ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
