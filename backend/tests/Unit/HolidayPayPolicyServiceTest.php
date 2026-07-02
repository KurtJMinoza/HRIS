<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\PayPolicyController;
use App\Models\Policy;
use App\Models\User;
use App\Services\AttendanceSessionService;
use App\Services\HolidayPayPolicyService;
use App\Services\HolidayPolicyCache;
use App\Services\HolidayService;
use App\Services\LeaveCreditService;
use App\Services\PayrollRulesEngineService;
use App\Services\PolicyResolverService;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeHolidayPayPolicyService;
use Tests\TestCase;

class HolidayPayPolicyServiceTest extends TestCase
{
    public function test_regular_holiday_unworked_paid_when_present_before_holiday(): void
    {
        $service = $this->service(workedDates: ['2026-06-11']);
        $holiday = $this->regularHoliday('2026-06-12');
        $result = $service->computeHolidayPay($this->employee(), [
            'date_key' => '2026-06-12',
            'worked' => false,
            'daily_rate' => 1000,
            'hourly_rate' => 125,
            'required_minutes' => 480,
        ], $holiday);

        $this->assertTrue($result['eligible']);
        $this->assertSame(1000.0, $result['holiday_premium_pay']);
        $this->assertSame('present_previous_workday', $result['qualification']['rule']);
    }

    public function test_regular_holiday_unworked_not_paid_after_unpaid_absence(): void
    {
        $service = $this->service();
        $holiday = $this->regularHoliday('2026-06-12');
        $result = $service->computeHolidayPay($this->employee(), [
            'date_key' => '2026-06-12',
            'worked' => false,
            'daily_rate' => 1000,
        ], $holiday);

        $this->assertFalse($result['eligible']);
        $this->assertSame(0.0, $result['holiday_premium_pay']);
        $this->assertSame('unpaid_absence_previous_workday', $result['qualification']['rule']);
    }

    public function test_selected_regular_holiday_is_paid_when_qualified(): void
    {
        $service = $this->service(workedDates: ['2026-06-11']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'holiday_selection_mode' => 'selected_regular_holidays',
                'holiday_ids' => [10],
            ],
        ]);
        $holiday = ['id' => 10, 'date' => '2026-06-12', 'type' => 'regular', 'name' => 'Independence Day'];

        $result = $service->evaluate($this->employee(), $holiday, '2026-06-12', false, $policy);

        $this->assertTrue($result['eligible']);
    }

    public function test_unselected_regular_holiday_does_not_receive_unworked_pay(): void
    {
        $service = $this->service(workedDates: ['2026-12-23']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'holiday_selection_mode' => 'selected_regular_holidays',
                'holiday_ids' => [10],
            ],
        ]);
        $holiday = ['id' => 20, 'date' => '2026-12-25', 'type' => 'regular', 'name' => 'Christmas Day'];

        $result = $service->evaluate($this->employee(), $holiday, '2026-12-25', false, $policy);

        $this->assertFalse($result['eligible']);
        $this->assertSame('regular_holiday_not_selected', $result['rule']);
    }

    public function test_unselected_regular_holiday_still_pays_employee_inside_holiday_scope(): void
    {
        $service = $this->service(workedDates: ['2026-12-24']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'holiday_selection_mode' => 'selected_regular_holidays',
                'holiday_ids' => [10],
            ],
        ]);
        $holiday = ['id' => 20, 'date' => '2026-12-25', 'type' => 'regular', 'name' => 'Christmas Day'];

        $result = $service->determineEligibility(
            $this->employee(),
            $holiday,
            '2026-12-25',
            false,
            $policy,
            true
        );

        $this->assertTrue($result['holiday_scope_match']);
        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_unworked_selection_does_not_block_worked_holiday_pay(): void
    {
        $service = $this->service();
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'holiday_selection_mode' => 'selected_regular_holidays',
                'holiday_ids' => [10],
            ],
        ]);
        $holiday = ['id' => 20, 'date' => '2026-12-25', 'type' => 'regular', 'name' => 'Christmas Day'];

        $result = $service->evaluate($this->employee(), $holiday, '2026-12-25', true, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame('worked_holiday', $result['rule']);
    }

    public function test_selected_special_holiday_receives_unworked_pay(): void
    {
        $service = $this->service(workedDates: ['2026-08-20']);
        $policy = $this->policyWithHolidayRules([
            'special_unworked' => [
                'unworked_pay_policy' => 'all_employment_types',
                'holiday_selection_mode' => 'selected_special_holidays',
                'holiday_ids' => [30],
            ],
        ]);
        $holiday = ['id' => 30, 'date' => '2026-08-21', 'type' => 'special', 'name' => 'TEST DAY'];

        $result = $service->evaluate($this->employee(), $holiday, '2026-08-21', false, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_unselected_special_holiday_still_pays_employee_inside_holiday_scope(): void
    {
        $service = $this->service(workedDates: ['2026-08-20']);
        $policy = $this->policyWithHolidayRules([
            'special_unworked' => [
                'unworked_pay_policy' => 'all_employment_types',
                'holiday_selection_mode' => 'selected_special_holidays',
                'holiday_ids' => [30],
            ],
        ]);
        $holiday = ['id' => 40, 'date' => '2026-08-21', 'type' => 'special', 'name' => 'Scoped Special Day'];

        $result = $service->determineEligibility(
            $this->employee(),
            $holiday,
            '2026-08-21',
            false,
            $policy,
            true
        );

        $this->assertTrue($result['holiday_scope_match']);
        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_admin_regular_holiday_coverage_pays_when_policy_unworked_pay_is_disabled(): void
    {
        $service = $this->service(workedDates: ['2026-06-12']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'unworked_pay_policy' => 'disabled',
                'holiday_selection_mode' => 'disabled',
            ],
        ]);
        $holiday = ['id' => 613, 'date' => '2026-06-13', 'type' => 'regular', 'name' => 'ALI Holiday'];

        $result = $service->computeHolidayPay($this->employee(), [
            'date_key' => '2026-06-13',
            'worked' => false,
            'daily_rate' => 1000,
            'hourly_rate' => 125,
            'required_minutes' => 480,
        ], $holiday, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame(1000.0, $result['holiday_premium_pay']);
        $this->assertSame(1.0, $result['unworked_multiplier']);
        $this->assertSame('holiday_module_coverage', $result['unworked_pay_source']);
        $this->assertSame('REGULAR_HOLIDAY_UNWORKED_PAY', $result['breakdown']['component_code']);
    }

    public function test_admin_special_holiday_coverage_pays_when_policy_uses_no_work_no_pay(): void
    {
        $service = $this->service(workedDates: ['2026-08-20']);
        $policy = $this->policyWithHolidayRules([
            'special_unworked' => [
                'unworked_pay_policy' => 'no_work_no_pay',
                'holiday_selection_mode' => 'no_work_no_pay_default',
            ],
        ]);
        $holiday = ['id' => 821, 'date' => '2026-08-21', 'type' => 'special', 'name' => 'ALI Special Holiday'];

        $result = $service->computeHolidayPay($this->employee(), [
            'date_key' => '2026-08-21',
            'worked' => false,
            'daily_rate' => 1000,
            'hourly_rate' => 125,
            'required_minutes' => 480,
        ], $holiday, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame(1000.0, $result['holiday_premium_pay']);
        $this->assertSame(1.0, $result['unworked_multiplier']);
        $this->assertSame('holiday_module_coverage', $result['unworked_pay_source']);
        $this->assertSame('SPECIAL_HOLIDAY_UNWORKED_PAY', $result['breakdown']['component_code']);
    }

    public function test_regular_holiday_worked_paid_even_after_unpaid_absence_before(): void
    {
        $service = $this->service();
        $holiday = $this->regularHoliday('2026-06-12');
        $result = $service->computeHolidayPay($this->employee(), [
            'date_key' => '2026-06-12',
            'worked' => true,
            'daily_rate' => 1000,
            'hourly_rate' => 125,
            'paid_regular_minutes' => 480,
        ], $holiday);

        $this->assertTrue($result['eligible']);
        $this->assertSame('worked_holiday', $result['qualification']['rule']);
        $this->assertGreaterThan(900.0, $result['holiday_premium_pay']);
        $this->assertLessThanOrEqual(1000.0, $result['holiday_premium_pay']);
    }

    public function test_special_non_working_unworked_is_no_pay(): void
    {
        $service = $this->service(workedDates: ['2026-08-20']);
        $holiday = ['date' => '2026-08-21', 'type' => 'special', 'name' => 'Special Day'];
        $result = $service->computeHolidayPay($this->employee(), [
            'date_key' => '2026-08-21',
            'worked' => false,
            'daily_rate' => 1000,
        ], $holiday);

        $this->assertFalse($result['eligible']);
        $this->assertSame(0.0, $result['holiday_premium_pay']);
        $this->assertSame('special_no_work_no_pay', $result['qualification']['rule']);
    }

    public function test_special_non_working_worked_uses_policy_multiplier(): void
    {
        $service = $this->service();
        $holiday = ['date' => '2026-08-21', 'type' => 'special', 'name' => 'Special Day'];
        $result = $service->computeHolidayPay($this->employee(), [
            'date_key' => '2026-08-21',
            'worked' => true,
            'daily_rate' => 1000,
            'hourly_rate' => 125,
            'paid_regular_minutes' => 480,
        ], $holiday);

        $this->assertTrue($result['eligible']);
        $this->assertSame(1.3, $result['worked_first8_multiplier']);
        $this->assertSame(300.0, $result['holiday_premium_pay']);
    }

    public function test_rest_days_are_skipped_to_previous_working_day(): void
    {
        $service = $this->service(workedDates: ['2026-06-12']);
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-06-15'), '2026-06-15', false, null);

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_following_workday_requirement_blocks_when_absent_after_holiday(): void
    {
        $service = $this->service(workedDates: ['2026-06-12']);
        $policy = $this->policyWithHolidayRules([
            'attendance' => ['require_following_workday_presence' => true],
        ]);
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-06-15'), '2026-06-15', false, $policy);

        $this->assertFalse($result['eligible']);
        $this->assertSame('unpaid_absence_following_workday', $result['rule']);
    }

    public function test_following_workday_requirement_qualifies_when_present_after_holiday(): void
    {
        $service = $this->service(workedDates: ['2026-06-12', '2026-06-16']);
        $policy = $this->policyWithHolidayRules([
            'attendance' => ['require_following_workday_presence' => true],
        ]);
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-06-15'), '2026-06-15', false, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_following_workday', $result['rule']);
    }

    public function test_following_workday_skips_rest_days_after_holiday(): void
    {
        $service = $this->service(workedDates: ['2026-06-11', '2026-06-15']);
        $policy = $this->policyWithHolidayRules([
            'attendance' => ['require_following_workday_presence' => true],
        ]);
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-06-12'), '2026-06-12', false, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_following_workday', $result['rule']);
    }

    public function test_two_successive_regular_holidays_use_first_holiday_condition(): void
    {
        $service = $this->service(
            workedDates: ['2026-04-01'],
            holidays: ['2026-04-02' => $this->regularHoliday('2026-04-02')]
        );
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-04-03'), '2026-04-03', false, null);

        $this->assertTrue($result['eligible']);
        $this->assertSame('successive_holiday_chain', $result['rule']);
    }

    public function test_successive_second_holiday_qualifies_when_first_was_worked(): void
    {
        $service = $this->service(
            workedDates: ['2026-04-02'],
            holidays: ['2026-04-02' => $this->regularHoliday('2026-04-02')]
        );
        $result = $service->evaluate($this->employee(), $this->regularHoliday('2026-04-03'), '2026-04-03', false, null);

        $this->assertTrue($result['eligible']);
        $this->assertSame('successive_holiday_worked_first', $result['rule']);
    }

    public function test_special_holiday_no_work_no_pay_when_absent(): void
    {
        $service = $this->service();
        $policy = $this->policyWithHolidayRules([
            'special_unworked' => ['unworked_pay_policy' => 'no_work_no_pay'],
        ]);
        $holiday = ['date' => '2026-08-21', 'type' => 'special', 'name' => 'Special Day'];

        $result = $service->evaluate($this->employee(), $holiday, '2026-08-21', false, $policy);

        $this->assertFalse($result['eligible']);
        $this->assertSame('special_no_work_no_pay', $result['rule']);
    }

    public function test_special_holiday_company_policy_pays_covered_employee_when_absent(): void
    {
        $service = $this->service(workedDates: ['2026-08-20']);
        $policy = $this->policyWithHolidayRules([
            'special_unworked' => [
                'unworked_pay_policy' => 'company_policy',
            ],
        ]);
        $holiday = ['date' => '2026-08-21', 'type' => 'special', 'name' => 'Special Day'];

        $result = $service->evaluate($this->employee(), $holiday, '2026-08-21', false, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_special_company_policy_does_not_filter_by_employment_type(): void
    {
        $service = $this->service(workedDates: ['2026-08-20']);
        $policy = $this->policyWithHolidayRules([
            'special_unworked' => [
                'unworked_pay_policy' => 'company_policy',
            ],
        ]);
        $holiday = ['date' => '2026-08-21', 'type' => 'special', 'name' => 'Special Day'];
        $consultant = new User([
            'employment_status' => 'regular',
            'employment_type' => 'consultant',
            'position' => 'Advisor',
            'schedule' => $this->employee()->schedule,
        ]);

        $result = $service->evaluate($consultant, $holiday, '2026-08-21', false, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_regular_holiday_covered_employee_with_previous_attendance(): void
    {
        $service = $this->service(workedDates: ['2026-06-11']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => ['unworked_pay_policy' => 'covered_employees'],
        ]);
        $holiday = ['date' => '2026-06-12', 'type' => 'regular', 'name' => 'Independence Day'];

        $result = $service->evaluate($this->employee(), $holiday, '2026-06-12', false, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_regular_holiday_pays_covered_regular_employee(): void
    {
        $service = $this->service(workedDates: ['2026-06-11']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'unworked_pay_policy' => 'covered_employees',
            ],
        ]);
        $holiday = ['date' => '2026-06-12', 'type' => 'regular', 'name' => 'Independence Day'];

        $determination = $service->determineEligibility($this->employee(), $holiday, '2026-06-12', false, $policy);
        $pay = $service->computeHolidayPay($this->employee(), [
            'date_key' => '2026-06-12',
            'worked' => false,
            'daily_rate' => 1000,
            'hourly_rate' => 125,
            'required_minutes' => 480,
        ], $holiday, $policy);

        $this->assertTrue($determination['eligible']);
        $this->assertSame(1000.0, $pay['holiday_premium_pay']);
    }

    public function test_regular_holiday_does_not_filter_by_employment_type(): void
    {
        $service = $this->service(workedDates: ['2026-06-11']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'unworked_pay_policy' => 'covered_employees',
            ],
        ]);
        $holiday = ['date' => '2026-06-12', 'type' => 'regular', 'name' => 'Independence Day'];
        $consultant = new User([
            'employment_status' => 'regular',
            'employment_type' => 'consultant',
            'position' => 'Advisor',
        ]);

        $determination = $service->determineEligibility($consultant, $holiday, '2026-06-12', false, $policy);
        $pay = $service->computeHolidayPay($consultant, [
            'date_key' => '2026-06-12',
            'worked' => false,
            'daily_rate' => 1000,
        ], $holiday, $policy);

        $this->assertTrue($determination['eligible']);
        $this->assertSame(1000.0, $pay['holiday_premium_pay']);
    }

    public function test_should_pay_unworked_holiday_for_regular_employee_without_attendance_log(): void
    {
        $holiday = ['id' => 99, 'date' => '2026-06-12', 'type' => 'regular', 'name' => 'Independence Day'];
        $service = $this->service(workedDates: ['2026-06-11'], holidays: ['2026-06-12' => $holiday]);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'unworked_pay_policy' => 'covered_employees',
            ],
        ]);

        $result = $service->shouldPayUnworkedHoliday($this->employee(), $holiday, '2026-06-12', $policy);

        $this->assertTrue($result['holiday_scope_match']);
        $this->assertTrue($result['previous_workday_passed']);
        $this->assertFalse($result['has_attendance_log']);
        $this->assertFalse($result['worked_on_holiday']);
        $this->assertTrue($result['should_pay_unworked_holiday']);
    }

    public function test_should_pay_unworked_holiday_without_employment_type_filter(): void
    {
        $holiday = ['id' => 99, 'date' => '2026-06-12', 'type' => 'regular', 'name' => 'Independence Day'];
        $service = $this->service(workedDates: ['2026-06-11'], holidays: ['2026-06-12' => $holiday]);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'unworked_pay_policy' => 'covered_employees',
            ],
        ]);
        $consultant = new User([
            'employment_status' => 'regular',
            'employment_type' => 'consultant',
            'position' => 'Advisor',
        ]);

        $result = $service->shouldPayUnworkedHoliday($consultant, $holiday, '2026-06-12', $policy);

        $this->assertTrue($result['should_pay_unworked_holiday']);
        $this->assertNull($result['skip_reason']);
    }

    public function test_regular_holiday_probationary_with_previous_attendance(): void
    {
        $service = $this->service(workedDates: ['2026-06-11']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'unworked_pay_policy' => 'covered_employees',
            ],
        ]);
        $holiday = ['date' => '2026-06-12', 'type' => 'regular', 'name' => 'Labor Day'];
        $probationary = new User([
            'employment_status' => 'probationary',
            'employment_type' => 'full_time',
            'position' => 'Staff',
            'schedule' => $this->employee()->schedule,
        ]);

        $result = $service->evaluate($probationary, $holiday, '2026-06-12', false, $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame('present_previous_workday', $result['rule']);
    }

    public function test_selected_regular_and_probationary_receive_unworked_regular_holiday_pay(): void
    {
        $service = $this->service(workedDates: ['2026-06-11']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'unworked_pay_policy' => 'selected_employment_types',
                'eligible_employment_types' => ['regular', 'probationary'],
            ],
        ]);
        $holiday = $this->regularHoliday('2026-06-12');

        foreach (['regular', 'probationary'] as $status) {
            $employee = $this->employee();
            $employee->employment_status = $status;
            $result = $service->computeHolidayPay($employee, [
                'date_key' => '2026-06-12',
                'worked' => false,
                'daily_rate' => 1000,
            ], $holiday, $policy);

            $this->assertTrue($result['eligible']);
            $this->assertSame(1000.0, $result['holiday_premium_pay']);
            $this->assertSame('REGULAR_HOLIDAY_UNWORKED_PAY', $result['breakdown']['component_code']);
        }
    }

    public function test_unselected_consultant_is_excluded_from_unworked_regular_holiday_pay(): void
    {
        $service = $this->service(workedDates: ['2026-06-11']);
        $policy = $this->policyWithHolidayRules([
            'regular_unworked' => [
                'unworked_pay_policy' => 'selected_employment_types',
                'eligible_employment_types' => ['regular', 'probationary'],
            ],
        ]);
        $consultant = $this->employee();
        $consultant->employment_type = 'consultant';

        $result = $service->computeHolidayPay($consultant, [
            'date_key' => '2026-06-12',
            'worked' => false,
            'daily_rate' => 1000,
        ], $this->regularHoliday('2026-06-12'), $policy);

        $this->assertFalse($result['eligible']);
        $this->assertSame(0.0, $result['holiday_premium_pay']);
        $this->assertSame('regular_employment_type_excluded', $result['qualification']['rule']);
    }

    public function test_selected_regular_receives_unworked_special_holiday_pay(): void
    {
        $service = $this->service(workedDates: ['2026-08-20']);
        $policy = $this->policyWithHolidayRules([
            'special_unworked' => [
                'unworked_pay_policy' => 'selected_employment_types',
                'eligible_employment_types' => ['regular'],
            ],
        ]);
        $result = $service->computeHolidayPay($this->employee(), [
            'date_key' => '2026-08-21',
            'worked' => false,
            'daily_rate' => 1000,
        ], ['date' => '2026-08-21', 'type' => 'special', 'name' => 'Special Day'], $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame(1000.0, $result['holiday_premium_pay']);
        $this->assertSame('SPECIAL_HOLIDAY_UNWORKED_PAY', $result['breakdown']['component_code']);
    }

    #[DataProvider('belowMinimumRates')]
    public function test_policy_validation_rejects_holiday_rates_below_dole_minimum(string $code, string $field, float $value): void
    {
        $controller = app(PayPolicyController::class);
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

    private function service(array $workedDates = [], array $paidLeaveDates = [], array $holidays = []): FakeHolidayPayPolicyService
    {
        $holidayService = Mockery::mock(HolidayService::class);
        $holidayService->shouldReceive('resolveHolidayForPayroll')
            ->andReturnUsing(fn (User $employee, string $dateKey) => $holidays[$dateKey] ?? null);
        $holidayService->shouldReceive('holidayCoversEmployee')->andReturn(true);

        $policyResolver = Mockery::mock(PolicyResolverService::class);
        $policyResolver->shouldReceive('getActivePolicy')->andReturn(null);
        $policyResolver->shouldReceive('getMultipliersForRule')->andReturnUsing(function (?Policy $policy, string $ruleCode) {
            return match ($ruleCode) {
                'RH' => ['first_8' => 2.0, 'ot' => 2.6, 'nd_base' => 2.0, 'nd_addon' => 0.10],
                'SH' => ['first_8' => 1.3, 'ot' => 1.69, 'nd_base' => 1.3, 'nd_addon' => 0.10],
                default => ['first_8' => 1.0, 'ot' => 1.25, 'nd_base' => 1.0, 'nd_addon' => 0.10],
            };
        });

        $rulesEngine = Mockery::mock(PayrollRulesEngineService::class);
        $rulesEngine->shouldReceive('holidayTypeFromHolidayRow')->andReturnUsing(function (array $holiday) {
            $type = strtolower((string) ($holiday['type'] ?? ''));

            return match ($type) {
                'regular' => 'regular',
                'double' => 'double',
                default => 'special',
            };
        });
        $rulesEngine->shouldReceive('resolveRuleCode')->andReturnUsing(function (bool $isRestDay, ?string $holidayType) {
            if ($holidayType === 'regular') {
                return $isRestDay ? 'RHRD' : 'RH';
            }

            return $isRestDay ? 'SHRD' : 'SH';
        });

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

        return new FakeHolidayPayPolicyService(
            $attendance,
            $holidayService,
            Mockery::mock(LeaveCreditService::class),
            $policyResolver,
            $rulesEngine,
            $workedDates,
            $paidLeaveDates,
        );
    }

    private function policyWithHolidayRules(array $holidayPolicy): Policy
    {
        return new Policy([
            'name' => 'Test Policy',
            'effective_date' => '2026-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
            'holiday_policy' => array_replace_recursive(Policy::DEFAULT_HOLIDAY_POLICY, $holidayPolicy),
        ]);
    }

    private function employee(): User
    {
        return new class([
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
        ]) extends User {
            public function getEffectiveCompanyId(): ?int
            {
                return 1;
            }
        };
    }

    /** @return array<string, string> */
    private function regularHoliday(string $date): array
    {
        return ['date' => $date, 'type' => 'regular', 'name' => 'Regular Holiday'];
    }
}
