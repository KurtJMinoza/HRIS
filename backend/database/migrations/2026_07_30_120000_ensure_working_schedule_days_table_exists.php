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
                $table->unsignedSmallInteger('expected_paid_minutes')->nullable();
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
                    $table->unsignedSmallInteger('expected_paid_minutes')->nullable()->after('break_minutes');
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

    public function down(): void
    {
        // This migration repairs a drifted production/dev schema. Do not drop the table on rollback.
    }

    private function backfillLegacyScheduleDays(): void
    {
        if (! Schema::hasTable('working_schedules') || ! Schema::hasTable('working_schedule_days')) {
            return;
        }

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
            ->chunkById(100, function ($schedules): void {
                foreach ($schedules as $schedule) {
                    $restDays = $this->restDaysFromValue($schedule->rest_days ?? null);
                    $now = now();

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
                                'updated_at' => $now,
                                'created_at' => $now,
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
