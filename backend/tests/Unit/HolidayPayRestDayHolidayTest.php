<?php

namespace Tests\Unit;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\Policy;
use App\Models\PolicyMultiplier;
use App\Models\User;
use App\Services\HolidayPayEvaluationService;
use App\Services\HolidayPayPolicyService;
use App\Services\PayrollComputationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HolidayPayRestDayHolidayTest extends TestCase
{
    private function tablesExist(): bool
    {
        try {
            DB::select('SELECT 1 FROM policies LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Sunday rest day; Mon–Sat workdays. */
    private function schedule(): array
    {
        return [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
        ];
    }

    /**
     * @return array{0: User, 1: Policy, 2: Holiday}
     */
    private function seedRegularHolidayOnRestDay(string $dateKey = '2026-08-02'): array
    {
        $suffix = substr(uniqid(), -6);
        $company = Company::create(['name' => 'RHRD-'.$suffix]);

        $employee = User::factory()->create([
            'company_id' => $company->id,
            'daily_rate' => 572.69,
            'schedule' => $this->schedule(),
            'employment_type' => 'full_time',
            'employment_status' => 'regular',
        ]);

        $policy = Policy::create([
            'name' => 'RHRD Policy '.$suffix,
            'company_id' => $company->id,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
            'holiday_policy' => Policy::DEFAULT_HOLIDAY_POLICY,
        ]);

        foreach ([
            'ORD' => ['first8' => 1.0, 'ot' => 1.25],
            'RD' => ['first8' => 1.30, 'ot' => 1.69],
            'RH' => ['first8' => 2.0, 'ot' => 2.60],
            'RHRD' => ['first8' => 2.60, 'ot' => 3.38],
        ] as $code => $mult) {
            PolicyMultiplier::create([
                'policy_id' => $policy->id,
                'condition_key' => $code,
                'first8_multiplier' => $mult['first8'],
                'ot_multiplier' => $mult['ot'],
                'nd_addon_multiplier' => 0.10,
            ]);
        }

        $holiday = Holiday::query()->create([
            'name' => 'Regular Holiday Sunday',
            'date' => $dateKey,
            'type' => 'regular',
            'scope' => 'company',
            'company_id' => $company->id,
            'status' => 'active',
        ]);

        return [$employee, $policy, $holiday];
    }

    private function clockFullDay(User $employee, string $dateKey): void
    {
        AttendanceLog::create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'verified_at' => Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila')->utc(),
            'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
        ]);
        AttendanceLog::create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'verified_at' => Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila')->utc(),
            'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
        ]);
    }

    public function test_regular_holiday_on_rest_day_uses_single_full_rate_holiday_line(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $dailyRate = 572.69;
        $dateKey = '2026-08-02'; // Sunday rest day
        [$employee] = $this->seedRegularHolidayOnRestDay($dateKey);
        $this->clockFullDay($employee, $dateKey);

        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $employee,
            $dateKey,
            Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila'),
            Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila'),
            $this->schedule(),
            $dailyRate,
            'Asia/Manila'
        );

        $this->assertSame('RHRD', $result['conditions']['rule_code'] ?? null);
        $this->assertSame(2.6, (float) ($result['conditions']['first_8'] ?? 0));
        $this->assertTrue($result['is_rest_day'] ?? false);

        $expectedTotal = round($dailyRate * 2.60, 2);

        $this->assertSame(0.0, (float) ($result['regular_pay'] ?? 0));
        $this->assertSame($expectedTotal, (float) ($result['holiday_premium_pay'] ?? 0));
        $this->assertSame($expectedTotal, (float) ($result['total_pay'] ?? 0));

        $restLine = collect($result['breakdown'] ?? [])
            ->first(fn (array $row) => ($row['component'] ?? '') === 'rest_day_worked_pay');
        $holidayLine = collect($result['breakdown'] ?? [])
            ->first(fn (array $row) => ($row['component'] ?? '') === 'holiday_premium');

        $this->assertNull($restLine);
        $this->assertSame('RESTDAY_REGULAR_HOLIDAY_PAY', $holidayLine['component_code'] ?? null);
        $this->assertSame($expectedTotal, (float) ($holidayLine['amount'] ?? 0));
        $this->assertSame(2.6, (float) ($holidayLine['multiplier'] ?? 0));
        $this->assertSame(2.6, (float) ($holidayLine['premium_multiplier'] ?? 0));
    }

    public function test_holiday_policy_service_uses_full_rhrd_rate_for_worked_rest_day(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $dailyRate = 572.69;
        [$employee] = $this->seedRegularHolidayOnRestDay();

        $result = app(HolidayPayPolicyService::class)->computeHolidayPay($employee, [
            'date_key' => '2026-08-02',
            'worked' => true,
            'daily_rate' => $dailyRate,
            'hourly_rate' => $dailyRate / 8,
            'required_minutes' => 480,
            'paid_regular_minutes' => 480,
            'is_rest_day' => true,
        ], ['date' => '2026-08-02', 'type' => 'regular', 'name' => 'Regular Holiday Sunday']);

        $expectedHolidayPay = round($dailyRate * 2.60, 2);
        $this->assertSame($expectedHolidayPay, $result['holiday_premium_pay']);
        $this->assertSame(2.6, (float) ($result['breakdown']['premium_multiplier'] ?? 0));
    }

    public function test_period_evaluation_does_not_overwrite_rhrd_holiday_premium(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $dailyRate = 572.69;
        $dateKey = '2026-08-02';
        [$employee, , $holiday] = $this->seedRegularHolidayOnRestDay($dateKey);
        $this->clockFullDay($employee, $dateKey);

        $day = app(PayrollComputationService::class)->computeDayPayroll(
            $employee,
            $dateKey,
            Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila'),
            Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila'),
            $this->schedule(),
            $dailyRate,
            'Asia/Manila'
        );

        $evaluation = app(HolidayPayEvaluationService::class)->evaluateHoliday(
            $employee,
            $holiday->toArray(),
            Carbon::parse($dateKey, 'Asia/Manila'),
            null,
            [
                'daily_rate' => $dailyRate,
                'hourly_rate' => $dailyRate / 8,
                'required_minutes' => 480,
                'paid_regular_minutes' => 480,
            ]
        );

        $expectedHolidayPay = round($dailyRate * 2.60, 2);
        $this->assertSame($expectedHolidayPay, (float) ($evaluation['amount'] ?? 0));
        $this->assertSame($expectedHolidayPay, (float) ($day['holiday_premium_pay'] ?? 0));
    }
}
