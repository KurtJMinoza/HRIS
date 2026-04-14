<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('statutory_contributions')) {
            Schema::table('statutory_contributions', function (Blueprint $table) {
                if (Schema::hasColumn('statutory_contributions', 'min_value')) {
                    $table->dropColumn('min_value');
                }
                if (Schema::hasColumn('statutory_contributions', 'max_value')) {
                    $table->dropColumn('max_value');
                }
                if (! Schema::hasColumn('statutory_contributions', 'salary_floor')) {
                    $table->decimal('salary_floor', 14, 2)->nullable()->after('employee_rate');
                }
                if (! Schema::hasColumn('statutory_contributions', 'salary_ceiling')) {
                    $table->decimal('salary_ceiling', 14, 2)->nullable()->after('salary_floor');
                }
                if (! Schema::hasColumn('statutory_contributions', 'monthly_cap')) {
                    $table->decimal('monthly_cap', 14, 2)->nullable()->after('tier_threshold');
                }
                if (! Schema::hasColumn('statutory_contributions', 'compliance_reference')) {
                    $table->string('compliance_reference', 120)->nullable()->after('metadata');
                }
            });
        }

        if (! Schema::hasTable('sss_brackets')) {
            Schema::create('sss_brackets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('statutory_contribution_id')->nullable()->constrained('statutory_contributions')->nullOnDelete();
                $table->decimal('range_start', 14, 2)->nullable();
                $table->decimal('range_end', 14, 2)->nullable();
                $table->string('range_label', 100);
                $table->decimal('msc', 14, 2);
                $table->decimal('employer_ss', 14, 2);
                $table->decimal('employer_ec', 14, 2);
                $table->decimal('employer_total', 14, 2);
                $table->decimal('employee_ss', 14, 2);
                $table->decimal('employee_total', 14, 2);
                $table->decimal('overall_total', 14, 2);
                $table->date('effective_from');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['effective_from', 'is_active']);
                $table->index(['range_start', 'range_end']);
            });
        } else {
            Schema::table('sss_brackets', function (Blueprint $table) {
                if (! Schema::hasColumn('sss_brackets', 'range_start')) {
                    $table->decimal('range_start', 14, 2)->nullable()->after('statutory_contribution_id');
                }
                if (! Schema::hasColumn('sss_brackets', 'range_end')) {
                    $table->decimal('range_end', 14, 2)->nullable()->after('range_start');
                }
                if (! Schema::hasColumn('sss_brackets', 'range_label')) {
                    $table->string('range_label', 100)->nullable()->after('range_end');
                }
                if (! Schema::hasColumn('sss_brackets', 'employer_ss')) {
                    $table->decimal('employer_ss', 14, 2)->nullable()->after('msc');
                }
                if (! Schema::hasColumn('sss_brackets', 'employer_ec')) {
                    $table->decimal('employer_ec', 14, 2)->nullable()->after('employer_ss');
                }
                if (! Schema::hasColumn('sss_brackets', 'employer_total')) {
                    $table->decimal('employer_total', 14, 2)->nullable()->after('employer_ec');
                }
                if (! Schema::hasColumn('sss_brackets', 'employee_ss')) {
                    $table->decimal('employee_ss', 14, 2)->nullable()->after('employer_total');
                }
                if (! Schema::hasColumn('sss_brackets', 'employee_total')) {
                    $table->decimal('employee_total', 14, 2)->nullable()->after('employee_ss');
                }
                if (! Schema::hasColumn('sss_brackets', 'overall_total')) {
                    $table->decimal('overall_total', 14, 2)->nullable()->after('employee_total');
                }
            });

            // Backfill richer columns from legacy rows if present.
            if (Schema::hasColumn('sss_brackets', 'salary_min') && Schema::hasColumn('sss_brackets', 'salary_max')) {
                $rows = DB::table('sss_brackets')->get();
                foreach ($rows as $row) {
                    $rangeStart = isset($row->range_start) ? (float) $row->range_start : (float) ($row->salary_min ?? 0);
                    $rangeEnd = isset($row->range_end) ? (float) $row->range_end : (float) ($row->salary_max ?? 0);
                    $msc = (float) ($row->msc ?? 0);
                    $employee = round($msc * 0.05, 2);
                    $employer = round($msc * 0.10, 2);
                    $ec = $msc >= 15000 ? 30.00 : 10.00;

                    DB::table('sss_brackets')->where('id', $row->id)->update([
                        'range_start' => $rangeStart,
                        'range_end' => $rangeEnd,
                        'range_label' => ($rangeStart <= 0 ? 'Below 5,250' : number_format($rangeStart, 2).' - '.number_format($rangeEnd, 2)),
                        'employer_ss' => $employer,
                        'employer_ec' => $ec,
                        'employer_total' => round($employer + $ec, 2),
                        'employee_ss' => $employee,
                        'employee_total' => $employee,
                        'overall_total' => round($employee + $employer + $ec, 2),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sss_brackets')) {
            Schema::table('sss_brackets', function (Blueprint $table) {
                foreach (['range_start', 'range_end', 'range_label', 'employer_ss', 'employer_ec', 'employer_total', 'employee_ss', 'employee_total', 'overall_total'] as $column) {
                    if (Schema::hasColumn('sss_brackets', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('statutory_contributions')) {
            Schema::table('statutory_contributions', function (Blueprint $table) {
                if (Schema::hasColumn('statutory_contributions', 'compliance_reference')) {
                    $table->dropColumn('compliance_reference');
                }
            });
        }
    }
};
