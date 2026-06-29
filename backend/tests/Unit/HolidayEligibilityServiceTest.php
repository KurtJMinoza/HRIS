<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\PayPolicyController;
use App\Models\Policy;
use App\Models\User;
use App\Services\AttendanceSessionService;
use App\Services\HolidayEligibilityService;
use App\Services\HolidayPolicyCache;
use App\Services\HolidayService;
use App\Services\LeaveCreditService;
use App\Services\PolicyResolverService;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HolidayEligibilityServiceTest extends TestCase
{
    public function test_regular_holiday_is_not_paid_after_unpaid_absence(): void
    {
        $service = $this->service();
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-06-12'), '2026-06-12', false, Policy::DEFAULT_HOLIDAY_POLICY);

        $this->assertFalse($result['eligible']);
        $this->assertSame('unpaid_absence_previous_workday', $result['rule']);
    }

    public function test_approved_paid_leave_before_regular_holiday_qualifies(): void
    {
        $service = $this->service(paidLeaveDates: ['2026-06-11']);
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-06-12'), '2026-06-12', false, Policy::DEFAULT_HOLIDAY_POLICY);

        $this->assertTrue($result['eligible']);
        $this->assertSame('paid_leave_previous_workday', $result['rule']);
    }

    public function test_rest_days_are_skipped_to_the_previous_working_day(): void
    {
        $service = $this->service(workedDates: ['2026-06-12']);
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-06-15'), '2026-06-15', false, Policy::DEFAULT_HOLIDAY_POLICY);

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_successive_regular_holidays_use_condition_before_first_holiday(): void
    {
        $service = $this->service(
            workedDates: ['2026-04-01'],
            holidays: ['2026-04-02' => $this->regularHoliday('2026-04-02')]
        );
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-04-03'), '2026-04-03', false, Policy::DEFAULT_HOLIDAY_POLICY);

        $this->assertTrue($result['eligible']);
        $this->assertSame('successive_holiday_chain', $result['rule']);
    }

    public function test_special_holiday_is_no_work_no_pay_unless_company_override_is_enabled(): void
    {
        $service = $this->service(workedDates: ['2026-08-20']);
        $holiday = ['date' => '2026-08-21', 'type' => 'special', 'name' => 'Special Day'];

        $default = $service->evaluate($this->employee(), $holiday, '2026-08-21', false, Policy::DEFAULT_HOLIDAY_POLICY);
        $this->assertFalse($default['eligible']);
        $this->assertSame(0.0, $service->unworkedMultiplier($holiday, Policy::DEFAULT_HOLIDAY_POLICY));

        $override = array_replace_recursive(Policy::DEFAULT_HOLIDAY_POLICY, [
            'pay_unworked_special' => true,
            'unworked_special_multiplier' => 1.30,
        ]);
        $paid = $service->evaluate($this->employee(), $holiday, '2026-08-21', false, $override);
        $this->assertTrue($paid['eligible']);
        $this->assertSame(1.3, $service->unworkedMultiplier($holiday, $override));
    }

    #[DataProvider('belowMinimumRates')]
    public function test_policy_validation_rejects_holiday_rates_below_dole_minimum(string $code, string $field, float $value): void
    {
        $controller = new PayPolicyController(Mockery::mock(PolicyResolverService::class));
        $method = new \ReflectionMethod($controller, 'validateDoleMinimums');

        try {
            $method->invoke($controller, ['multipliers' => [[
                'condition_key' => $code,
                'first8_multiplier' => $field === 'first8_multiplier' ? $value : Policy::HOLIDAY_MULTIPLIER_MINIMUMS[$code]['first8_multiplier'],
                'ot_multiplier' => $field === 'ot_multiplier' ? $value : Policy::HOLIDAY_MULTIPLIER_MINIMUMS[$code]['ot_multiplier'],
                'nd_addon_multiplier' => 0.10,
            ]]]);
            $this->fail('Expected the DOLE floor validation to reject the rate.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('below the minimum standard required by DOLE', json_encode($exception->errors()));
        }
    }

    public static function belowMinimumRates(): array
    {
        return [
            'regular holiday' => ['RH', 'first8_multiplier', 1.99],
            'regular holiday rest day' => ['RHRD', 'first8_multiplier', 2.59],
            'special holiday' => ['SH', 'first8_multiplier', 1.29],
            'special holiday overtime' => ['SH', 'ot_multiplier', 1.68],
        ];
    }

    public function test_cache_key_uses_required_company_namespace(): void
    {
        $this->assertSame('policy:holiday:42', HolidayPolicyCache::policyKey(42));
        $this->assertSame(1800, HolidayPolicyCache::TTL_SECONDS);
    }

    public function test_leave_credits_do_not_replace_holiday_pay(): void
    {
        $holidayService = Mockery::mock(HolidayService::class);
        $holidayService->shouldReceive('resolveHolidayForPayroll')
            ->andReturnUsing(fn (User $employee, string $dateKey) => $dateKey === '2026-06-12'
                ? $this->regularHoliday($dateKey)
                : null);
        $leaveCredits = new LeaveCreditService($holidayService);

        $this->assertSame(
            1,
            $leaveCredits->billableCreditDaysForUser($this->employee(), 'vacation', '2026-06-11', '2026-06-12')
        );
    }

    private function service(array $workedDates = [], array $paidLeaveDates = [], array $holidays = []): FakeHolidayEligibilityService
    {
        $holidayService = Mockery::mock(HolidayService::class);
        $holidayService->shouldReceive('resolveHolidayForPayroll')
            ->andReturnUsing(fn (User $employee, string $dateKey) => $holidays[$dateKey] ?? null);

        return new FakeHolidayEligibilityService(
            Mockery::mock(AttendanceSessionService::class),
            $holidayService,
            Mockery::mock(LeaveCreditService::class),
            $workedDates,
            $paidLeaveDates,
        );
    }

    private function employee(): User
    {
        return new User([
            'employment_status' => 'regular',
            'employment_type' => 'full_time',
            'position' => 'Staff',
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
    private function regularHoliday(string $date): array
    {
        return ['date' => $date, 'type' => 'regular', 'name' => 'Regular Holiday'];
    }
}

class FakeHolidayEligibilityService extends HolidayEligibilityService
{
    public function __construct(
        AttendanceSessionService $attendanceSession,
        HolidayService $holidayService,
        LeaveCreditService $leaveCreditService,
        private readonly array $workedDates,
        private readonly array $paidLeaveDates,
    ) {
        parent::__construct($attendanceSession, $holidayService, $leaveCreditService);
    }

    protected function workedOn(User $employee, string $dateKey): bool
    {
        return in_array($dateKey, $this->workedDates, true);
    }

    protected function hasApprovedPaidLeave(User $employee, string $dateKey): bool
    {
        return in_array($dateKey, $this->paidLeaveDates, true);
    }
}
