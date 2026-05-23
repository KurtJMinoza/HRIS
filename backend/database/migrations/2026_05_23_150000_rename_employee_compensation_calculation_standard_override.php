<?php

use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Support\CalculationStandard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_compensation_components')) {
            return;
        }

        if (Schema::hasColumn('employee_compensation_components', 'calculation_standard')
            && ! Schema::hasColumn('employee_compensation_components', 'calculation_standard_override')) {
            Schema::table('employee_compensation_components', function (Blueprint $table) {
                $table->renameColumn('calculation_standard', 'calculation_standard_override');
            });
        } elseif (! Schema::hasColumn('employee_compensation_components', 'calculation_standard_override')) {
            Schema::table('employee_compensation_components', function (Blueprint $table) {
                $table->string('calculation_standard_override', 32)->nullable()->after('calculation_type');
            });
        }

        if (! Schema::hasColumn('employee_compensation_components', 'calculation_standard_override')) {
            return;
        }

        EmployeeCompensationComponent::query()
            ->with('payComponent:id,calculation_standard')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $override = CalculationStandard::normalizeForStorage($row->calculation_standard_override);
                    $default = CalculationStandard::normalizeDefault($row->payComponent?->calculation_standard);
                    if ($override === null || $override === $default) {
                        if ($row->calculation_standard_override !== null) {
                            $row->forceFill(['calculation_standard_override' => null])->saveQuietly();
                        }
                    } elseif ($row->calculation_standard_override !== $override) {
                        $row->forceFill(['calculation_standard_override' => $override])->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_compensation_components')) {
            return;
        }

        if (Schema::hasColumn('employee_compensation_components', 'calculation_standard_override')
            && ! Schema::hasColumn('employee_compensation_components', 'calculation_standard')) {
            Schema::table('employee_compensation_components', function (Blueprint $table) {
                $table->renameColumn('calculation_standard_override', 'calculation_standard');
            });

            EmployeeCompensationComponent::query()
                ->with('payComponent:id,calculation_standard')
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $meta = CalculationStandard::resolveMetadata(
                            $row->getAttribute('calculation_standard'),
                            $row->payComponent?->calculation_standard
                        );
                        $row->forceFill([
                            'calculation_standard' => $meta['resolved_calculation_standard'],
                        ])->saveQuietly();
                    }
                });
        }
    }
};
