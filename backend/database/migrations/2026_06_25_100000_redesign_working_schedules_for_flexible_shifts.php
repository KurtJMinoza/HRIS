<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_schedules', function (Blueprint $table): void {
            if (! Schema::hasColumn('working_schedules', 'schedule_code')) {
                $table->string('schedule_code', 32)->nullable()->after('name');
            }

            if (! Schema::hasColumn('working_schedules', 'shift_type')) {
                $table->string('shift_type', 30)->default('fixed')->after('schedule_code');
            }

            if (! Schema::hasColumn('working_schedules', 'crosses_midnight')) {
                $table->boolean('crosses_midnight')->default(false)->after('time_out');
            }

            if (! Schema::hasColumn('working_schedules', 'expected_paid_minutes')) {
                $table->unsignedInteger('expected_paid_minutes')->nullable()->after('crosses_midnight');
            }

            if (! Schema::hasColumn('working_schedules', 'breaks')) {
                $table->json('breaks')->nullable()->after('break_end');
            }

            if (! Schema::hasColumn('working_schedules', 'work_blocks')) {
                $table->json('work_blocks')->nullable()->after('breaks');
            }

            if (! Schema::hasColumn('working_schedules', 'half_day_threshold_minutes')) {
                $table->unsignedInteger('half_day_threshold_minutes')->nullable()->after('expected_paid_minutes');
            }

            if (! Schema::hasColumn('working_schedules', 'flexible_required_minutes')) {
                $table->unsignedInteger('flexible_required_minutes')->nullable()->after('half_day_threshold_minutes');
            }

            if (! Schema::hasColumn('working_schedules', 'flexible_earliest_in')) {
                $table->time('flexible_earliest_in')->nullable()->after('flexible_required_minutes');
            }

            if (! Schema::hasColumn('working_schedules', 'flexible_latest_out')) {
                $table->time('flexible_latest_out')->nullable()->after('flexible_earliest_in');
            }

            if (! Schema::hasColumn('working_schedules', 'core_hours_start')) {
                $table->time('core_hours_start')->nullable()->after('flexible_latest_out');
            }

            if (! Schema::hasColumn('working_schedules', 'core_hours_end')) {
                $table->time('core_hours_end')->nullable()->after('core_hours_start');
            }

            if (! Schema::hasColumn('working_schedules', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('rest_days');
            }

            if (! Schema::hasColumn('working_schedules', 'description')) {
                $table->text('description')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('working_schedules', function (Blueprint $table): void {
            $columns = [
                'schedule_code',
                'shift_type',
                'crosses_midnight',
                'expected_paid_minutes',
                'half_day_threshold_minutes',
                'work_blocks',
                'flexible_required_minutes',
                'flexible_earliest_in',
                'flexible_latest_out',
                'core_hours_start',
                'core_hours_end',
                'is_active',
                'description',
            ];

            $toDrop = [];
            foreach ($columns as $col) {
                if (Schema::hasColumn('working_schedules', $col)) {
                    $toDrop[] = $col;
                }
            }

            if ($toDrop !== []) {
                $table->dropColumn($toDrop);
            }
        });
    }
};
