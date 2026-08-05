<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkingSchedule;
use App\Models\WorkingScheduleDayOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFlexibleScheduleSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_multiple_flexible_shift_options_for_a_day(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $days = $this->defaultFlexibleDays();

        $days[0]['options'][] = [
            'option_name' => 'Option 2',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'break_is_paid' => true,
            'grace_period_minutes' => 5,
            'early_timein_minutes' => 60,
            'overtime_buffer_minutes' => 15,
            'crosses_midnight' => false,
            'is_default' => false,
            'sequence' => 2,
        ];

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/schedules', [
            'name' => 'Flexible Multi Save',
            'schedule_code' => 'FMS-01',
            'shift_type' => WorkingSchedule::SHIFT_FLEXIBLE,
            'time_in' => '08:00',
            'time_out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 5,
            'early_timein_minutes' => 60,
            'overtime_buffer_minutes' => 15,
            'rest_days' => ['sat', 'sun'],
            'days' => $days,
        ]);

        $response->assertCreated()
            ->assertJsonPath('schedule.days.0.options.0.option_name', 'Default')
            ->assertJsonPath('schedule.days.0.options.1.option_name', 'Option 2')
            ->assertJsonPath('schedule.days.0.options.1.break_is_paid', true);

        $schedule = WorkingSchedule::query()
            ->with('days.options')
            ->where('schedule_code', 'FMS-01')
            ->firstOrFail();

        $mondayOptions = $schedule->days
            ->firstWhere('day_of_week', 'mon')
            ->options;

        $this->assertCount(2, $mondayOptions);
        $this->assertTrue((bool) $mondayOptions->firstWhere('option_name', 'Option 2')->break_is_paid);
        $this->assertSame(2, WorkingScheduleDayOption::query()->whereIn('id', $mondayOptions->pluck('id'))->count());
    }

    public function test_admin_can_add_shift_option_when_updating_flexible_schedule(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/schedules', [
            'name' => 'Flexible Update Save',
            'schedule_code' => 'FUS-01',
            'shift_type' => WorkingSchedule::SHIFT_FLEXIBLE,
            'time_in' => '08:00',
            'time_out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 5,
            'early_timein_minutes' => 60,
            'overtime_buffer_minutes' => 15,
            'rest_days' => ['sat', 'sun'],
            'days' => $this->defaultFlexibleDays(),
        ]);
        $create->assertCreated();

        $scheduleId = $create->json('schedule.id');
        $days = $create->json('schedule.days');
        $days[0]['options'][] = [
            'option_name' => 'Late Shift',
            'time_in' => '10:00',
            'time_out' => '19:00',
            'break_start' => '14:00',
            'break_end' => '15:00',
            'break_is_paid' => false,
            'grace_period_minutes' => 10,
            'early_timein_minutes' => 60,
            'overtime_buffer_minutes' => 20,
            'crosses_midnight' => false,
            'is_default' => false,
            'sequence' => 2,
        ];

        $update = $this->actingAs($admin, 'sanctum')->patchJson("/api/admin/schedules/{$scheduleId}", [
            'name' => 'Flexible Update Save',
            'schedule_code' => 'FUS-01',
            'shift_type' => WorkingSchedule::SHIFT_FLEXIBLE,
            'time_in' => '08:00',
            'time_out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 5,
            'early_timein_minutes' => 60,
            'overtime_buffer_minutes' => 15,
            'rest_days' => ['sat', 'sun'],
            'days' => $days,
        ]);

        $update->assertOk()
            ->assertJsonPath('schedule.days.0.options.1.option_name', 'Late Shift')
            ->assertJsonPath('schedule.days.0.options.1.time_in', '10:00');

        $schedule = WorkingSchedule::query()
            ->with('days.options')
            ->findOrFail($scheduleId);

        $mondayOptions = $schedule->days
            ->firstWhere('day_of_week', 'mon')
            ->options;

        $this->assertCount(2, $mondayOptions);
        $this->assertSame('Late Shift', $mondayOptions->last()->option_name);
        $this->assertSame('10:00:00', (string) $mondayOptions->last()->time_in);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultFlexibleDays(): array
    {
        return collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
            ->map(fn (string $day) => [
                'day_of_week' => $day,
                'is_working_day' => ! in_array($day, ['sat', 'sun'], true),
                'time_in' => ! in_array($day, ['sat', 'sun'], true) ? '08:00' : null,
                'time_out' => ! in_array($day, ['sat', 'sun'], true) ? '17:00' : null,
                'break_start' => ! in_array($day, ['sat', 'sun'], true) ? '12:00' : null,
                'break_end' => ! in_array($day, ['sat', 'sun'], true) ? '13:00' : null,
                'grace_period_minutes' => 5,
                'early_timein_minutes' => 60,
                'overtime_buffer_minutes' => 15,
                'crosses_midnight' => false,
                'options' => ! in_array($day, ['sat', 'sun'], true)
                    ? [[
                        'option_name' => 'Default',
                        'time_in' => '08:00',
                        'time_out' => '17:00',
                        'break_start' => '12:00',
                        'break_end' => '13:00',
                        'break_is_paid' => false,
                        'grace_period_minutes' => 5,
                        'early_timein_minutes' => 60,
                        'overtime_buffer_minutes' => 15,
                        'crosses_midnight' => false,
                        'is_default' => true,
                        'sequence' => 1,
                    ]]
                    : [],
            ])
            ->all();
    }
}
