<?php

namespace Tests\Unit;

use App\Models\Policy;
use App\Models\User;
use App\Services\AttendanceSessionService;
use App\Services\HolidayPayAttendanceStatusRegistry;
use App\Services\HolidayPayRuleEngine;
use App\Services\HolidayService;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Mockery;
use Tests\Support\FakeHolidayPayRuleEngine;
use Tests\TestCase;

class HolidayPayRuleEngineTest extends TestCase
{
    private const HOLIDAY = '2026-06-12';

    public function test_scenario_1_previous_working_day_only_present_before(): void
    {
        $engine = $this->engine(workedDates: ['2026-06-11']);
        $result = $engine->evaluateRegularUnworked(
            $this->employee(),
            $this->regularHoliday(),
            self::HOLIDAY,
            $this->dolePolicy()
        );

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_scenario_2_previous_and_next_both_present(): void
    {
        $engine = $this->engine(workedDates: ['2026-06-11', '2026-06-15']);
        $policy = $this->customPolicy(['minimum_condition' => 'previous_and_next']);
        $result = $engine->evaluateRegularUnworked(
            $this->employee(),
            $this->regularHoliday(),
            self::HOLIDAY,
            $policy
        );

        $this->assertTrue($result['eligible']);
        $this->assertSame('qualified_previous_and_next', $result['rule']);
    }

    public function test_scenario_3_previous_and_next_absent_after(): void
    {
        $engine = $this->engine(workedDates: ['2026-06-11']);
        $policy = $this->customPolicy(['minimum_condition' => 'previous_and_next']);
        $result = $engine->evaluateRegularUnworked(
            $this->employee(),
            $this->regularHoliday(),
            self::HOLIDAY,
            $policy
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame('failed_previous_and_next', $result['rule']);
    }

    public function test_scenario_4_previous_or_next_present_after_only(): void
    {
        $engine = $this->engine(workedDates: ['2026-06-15']);
        $policy = $this->customPolicy(['minimum_condition' => 'previous_or_next']);
        $result = $engine->evaluateRegularUnworked(
            $this->employee(),
            $this->regularHoliday(),
            self::HOLIDAY,
            $policy
        );

        $this->assertTrue($result['eligible']);
        $this->assertSame('qualified_previous_or_next', $result['rule']);
    }

    public function test_scenario_5_always_pay_override(): void
    {
        $engine = $this->engine();
        $policy = array_replace_recursive($this->dolePolicy(), [
            'regular_unworked' => ['always_pay' => true],
        ]);
        $result = $engine->evaluateRegularUnworked(
            $this->employee(),
            $this->regularHoliday(),
            self::HOLIDAY,
            $policy
        );

        $this->assertTrue($result['eligible']);
        $this->assertSame('regular_always_pay_override', $result['rule']);
    }

    /** @param  list<string>  $workedDates */
    private function engine(array $workedDates = [], array $paidLeaveDates = []): HolidayPayRuleEngine
    {
        $holidayService = Mockery::mock(HolidayService::class);
        $holidayService->shouldReceive('resolveHolidayForPayroll')->andReturn(null);

        $attendance = Mockery::mock(AttendanceSessionService::class);
        $attendance->shouldReceive('getTimesForDate')->andReturnUsing(function ($user, $dateKey) use ($workedDates) {
            if (in_array($dateKey, $workedDates, true)) {
                $tz = 'Asia/Manila';

                return [
                    Carbon::parse("{$dateKey} 08:00:00", $tz),
                    Carbon::parse("{$dateKey} 17:00:00", $tz),
                ];
            }

            return [null, null];
        });

        return new FakeHolidayPayRuleEngine(
            $attendance,
            $holidayService,
            Mockery::mock(LeaveCreditService::class),
            app(HolidayPayAttendanceStatusRegistry::class),
            $workedDates,
            $paidLeaveDates,
        );
    }

    /** @return array<string, mixed> */
    private function dolePolicy(): array
    {
        return Policy::DEFAULT_HOLIDAY_POLICY;
    }

    /** @param  array<string, mixed>  $ruleOverrides */
    private function customPolicy(array $ruleOverrides): array
    {
        return array_replace_recursive(Policy::DEFAULT_HOLIDAY_POLICY, [
            'regular_unworked' => [
                'policy_mode' => 'custom',
                'attendance_rule' => $ruleOverrides,
            ],
        ]);
    }

    private function employee(): User
    {
        return new User([
            'employment_status' => 'regular',
            'employment_type' => 'full_time',
            'schedule' => [
                'sun' => null,
                'mon' => ['in' => '08:00', 'out' => '17:00'],
                'tue' => ['in' => '08:00', 'out' => '17:00'],
                'wed' => ['in' => '08:00', 'out' => '17:00'],
                'thu' => ['in' => '08:00', 'out' => '17:00'],
                'fri' => ['in' => '08:00', 'out' => '17:00'],
                'sat' => null,
            ],
        ]);
    }

    /** @return array<string, string> */
    private function regularHoliday(): array
    {
        return ['date' => self::HOLIDAY, 'type' => 'regular', 'name' => 'Independence Day'];
    }
}
