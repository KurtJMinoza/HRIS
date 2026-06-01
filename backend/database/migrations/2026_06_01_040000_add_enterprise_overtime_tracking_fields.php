<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('overtimes')) {
            return;
        }

        Schema::table('overtimes', function (Blueprint $table): void {
            if (! Schema::hasColumn('overtimes', 'approved_ot_start')) {
                $table->time('approved_ot_start')->nullable()->after('expected_end_time');
            }
            if (! Schema::hasColumn('overtimes', 'approved_ot_end')) {
                $table->time('approved_ot_end')->nullable()->after('approved_ot_start');
            }
            if (! Schema::hasColumn('overtimes', 'approved_ot_hours')) {
                $table->decimal('approved_ot_hours', 8, 2)->nullable()->after('approved_ot_end');
            }
            if (! Schema::hasColumn('overtimes', 'actual_rendered_ot_hours')) {
                $table->decimal('actual_rendered_ot_hours', 8, 2)->default(0)->after('approved_ot_hours');
            }
            if (! Schema::hasColumn('overtimes', 'payable_ot_hours')) {
                $table->decimal('payable_ot_hours', 8, 2)->default(0)->after('actual_rendered_ot_hours');
            }
            if (! Schema::hasColumn('overtimes', 'unapproved_ot_hours')) {
                $table->decimal('unapproved_ot_hours', 8, 2)->default(0)->after('payable_ot_hours');
            }
            if (! Schema::hasColumn('overtimes', 'overtime_reduction_reason')) {
                $table->string('overtime_reduction_reason')->nullable()->after('unapproved_ot_hours');
            }
        });

        DB::table('overtimes')
            ->where('status', 'approved')
            ->update([
                'approved_ot_start' => DB::raw('schedule_end'),
                'approved_ot_end' => DB::raw('expected_end_time'),
                'approved_ot_hours' => DB::raw('computed_hours'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('overtimes')) {
            return;
        }

        Schema::table('overtimes', function (Blueprint $table): void {
            foreach ([
                'overtime_reduction_reason',
                'unapproved_ot_hours',
                'payable_ot_hours',
                'actual_rendered_ot_hours',
                'approved_ot_hours',
                'approved_ot_end',
                'approved_ot_start',
            ] as $column) {
                if (Schema::hasColumn('overtimes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
