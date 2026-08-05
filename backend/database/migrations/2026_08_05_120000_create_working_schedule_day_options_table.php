<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('working_schedule_day_options')) {
            Schema::create('working_schedule_day_options', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('working_schedule_day_id')->constrained('working_schedule_days')->cascadeOnDelete();
                $table->string('option_name', 80);
                $table->time('time_in');
                $table->time('time_out');
                $table->time('break_start')->nullable();
                $table->time('break_end')->nullable();
                $table->unsignedSmallInteger('break_minutes')->nullable();
                $table->unsignedInteger('expected_paid_minutes')->nullable();
                $table->unsignedSmallInteger('grace_period_minutes')->nullable();
                $table->unsignedSmallInteger('early_timein_minutes')->nullable();
                $table->unsignedSmallInteger('overtime_buffer_minutes')->nullable();
                $table->unsignedSmallInteger('half_day_threshold_minutes')->nullable();
                $table->boolean('crosses_midnight')->default(false);
                $table->boolean('is_default')->default(false);
                $table->unsignedSmallInteger('matching_start_tolerance_minutes')->nullable();
                $table->unsignedSmallInteger('matching_end_tolerance_minutes')->nullable();
                $table->unsignedSmallInteger('sequence')->default(1);
                $table->timestamps();

                $table->unique(['working_schedule_day_id', 'option_name'], 'ws_day_option_name_unique');
                $table->index(['working_schedule_day_id', 'is_default'], 'ws_day_option_default_idx');
            });
        }

        if (Schema::hasTable('working_schedule_days')) {
            $now = now();
            $existingOptionDayIds = DB::table('working_schedule_day_options')
                ->pluck('working_schedule_day_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $query = DB::table('working_schedule_days')
                ->where('is_working_day', true)
                ->whereNotNull('time_in')
                ->whereNotNull('time_out');

            if ($existingOptionDayIds !== []) {
                $query->whereNotIn('id', $existingOptionDayIds);
            }

            $rows = $query->get();
            foreach ($rows as $day) {
                DB::table('working_schedule_day_options')->insert([
                    'working_schedule_day_id' => $day->id,
                    'option_name' => 'Default',
                    'time_in' => $day->time_in,
                    'time_out' => $day->time_out,
                    'break_start' => $day->break_start,
                    'break_end' => $day->break_end,
                    'break_minutes' => $day->break_minutes,
                    'expected_paid_minutes' => $day->expected_paid_minutes ?? null,
                    'grace_period_minutes' => $day->grace_period_minutes ?? null,
                    'early_timein_minutes' => $day->early_timein_minutes ?? null,
                    'overtime_buffer_minutes' => $day->overtime_buffer_minutes ?? null,
                    'half_day_threshold_minutes' => $day->half_day_threshold_minutes ?? null,
                    'crosses_midnight' => (bool) ($day->crosses_midnight ?? false),
                    'is_default' => true,
                    'sequence' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('working_schedule_day_options');
    }
};
