<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function up(): void
    {
        $this->ensureWorkingScheduleDaysTable();

        if (! Schema::hasTable('working_schedule_day_options')) {
            Schema::create('working_schedule_day_options', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('working_schedule_day_id');
                $table->string('option_name', 80);
                $table->time('time_in');
                $table->time('time_out');
                $table->time('break_start')->nullable();
                $table->time('break_end')->nullable();
                $table->boolean('break_is_paid')->default(false);
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

            if (Schema::hasTable('working_schedule_days')) {
                Schema::table('working_schedule_day_options', function (Blueprint $table): void {
                    $table->foreign('working_schedule_day_id', 'ws_day_option_day_fk')
                        ->references('id')
                        ->on('working_schedule_days')
                        ->cascadeOnDelete();
                });
            }
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
                    'break_is_paid' => false,
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

    private function ensureWorkingScheduleDaysTable(): void
    {
        if (! Schema::hasTable('working_schedules')) {
            return;
        }

        if (! Schema::hasTable('working_schedule_days')) {
            Schema::create('working_schedule_days', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('working_schedule_id')->constrained('working_schedules')->cascadeOnDelete();
                $table->string('day_of_week', 3);
                $table->boolean('is_working_day')->default(true);
                $table->time('time_in')->nullable();
                $table->time('time_out')->nullable();
                $table->time('break_start')->nullable();
                $table->time('break_end')->nullable();
                $table->unsignedSmallInteger('break_minutes')->nullable();
                $table->unsignedInteger('expected_paid_minutes')->nullable();
                $table->unsignedSmallInteger('grace_period_minutes')->nullable();
                $table->unsignedSmallInteger('early_timein_minutes')->nullable();
                $table->unsignedSmallInteger('overtime_buffer_minutes')->nullable();
                $table->unsignedSmallInteger('half_day_threshold_minutes')->nullable();
                $table->boolean('crosses_midnight')->default(false);
                $table->timestamps();

                $table->unique(['working_schedule_id', 'day_of_week']);
            });
        } else {
            Schema::table('working_schedule_days', function (Blueprint $table): void {
                if (! Schema::hasColumn('working_schedule_days', 'expected_paid_minutes')) {
                    $table->unsignedInteger('expected_paid_minutes')->nullable()->after('break_minutes');
                }
                if (! Schema::hasColumn('working_schedule_days', 'early_timein_minutes')) {
                    $table->unsignedSmallInteger('early_timein_minutes')->nullable()->after('grace_period_minutes');
                }
                if (! Schema::hasColumn('working_schedule_days', 'overtime_buffer_minutes')) {
                    $table->unsignedSmallInteger('overtime_buffer_minutes')->nullable()->after('early_timein_minutes');
                }
            });
        }

        $this->backfillLegacyScheduleDays();
    }

    private function backfillLegacyScheduleDays(): void
    {
        $now = now();

        DB::table('working_schedules')
            ->orderBy('id')
            ->select([
                'id',
                'time_in',
                'time_out',
                'break_start',
                'break_end',
                'expected_paid_minutes',
                'grace_period_minutes',
                'early_timein_minutes',
                'overtime_buffer_minutes',
                'half_day_threshold_minutes',
                'crosses_midnight',
                'rest_days',
            ])
            ->chunkById(100, function ($schedules) use ($now): void {
                foreach ($schedules as $schedule) {
                    $restDays = $this->restDaysFromValue($schedule->rest_days ?? null);

                    foreach (self::DAY_KEYS as $dayKey) {
                        DB::table('working_schedule_days')->updateOrInsert(
                            [
                                'working_schedule_id' => (int) $schedule->id,
                                'day_of_week' => $dayKey,
                            ],
                            [
                                'is_working_day' => ! in_array($dayKey, $restDays, true),
                                'time_in' => $schedule->time_in,
                                'time_out' => $schedule->time_out,
                                'break_start' => $schedule->break_start,
                                'break_end' => $schedule->break_end,
                                'break_minutes' => null,
                                'expected_paid_minutes' => $schedule->expected_paid_minutes,
                                'grace_period_minutes' => $schedule->grace_period_minutes,
                                'early_timein_minutes' => $schedule->early_timein_minutes,
                                'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes,
                                'half_day_threshold_minutes' => $schedule->half_day_threshold_minutes,
                                'crosses_midnight' => (bool) ($schedule->crosses_midnight ?? false),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]
                        );
                    }
                }
            });
    }

    /**
     * @return list<string>
     */
    private function restDaysFromValue(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                $decoded = preg_split('/\s*,\s*/', $value) ?: [];
            }
        } else {
            $decoded = [];
        }

        return collect($decoded)
            ->map(fn ($day) => strtolower(trim((string) $day)))
            ->filter(fn (string $day) => in_array($day, self::DAY_KEYS, true))
            ->values()
            ->all();
    }
};
