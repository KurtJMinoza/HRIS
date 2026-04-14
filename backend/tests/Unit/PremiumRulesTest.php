<?php

namespace Tests\Unit;

use App\Services\PayrollRulesEngineService;
use App\Services\TimeSegmentationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PremiumRulesTest extends TestCase
{
    /**
     * ND split: 10h shift with night minutes only in the OT segment.
     * Clock in 2PM, clock out 12AM: hours 9–10 (10PM–12AM) are OT + night.
     * ND on OT must use otMult, not first_8 base.
     */
    public function test_nd_on_overtime_hours_are_classified_correctly(): void
    {
        $tz = 'Asia/Manila';
        $date = '2026-03-18'; // Wednesday
        $timeIn = Carbon::parse("{$date} 14:00", $tz);
        $timeOut = Carbon::parse('2026-03-19 00:00', $tz); // midnight next day

        $service = app(TimeSegmentationService::class);
        $seg = $service->segment($timeIn, $timeOut, $tz);

        $this->assertSame(480, $seg['regular_minutes'], 'First 8 hours = regular');
        $this->assertSame(120, $seg['overtime_minutes'], 'Last 2 hours = OT');
        $this->assertSame(120, $seg['night_minutes'], '10PM–12AM = 2h night');
        $this->assertSame(0, $seg['nd_regular_minutes'], 'No night in regular segment');
        $this->assertSame(120, $seg['nd_overtime_minutes'], 'All night minutes fall in OT');
    }

    /**
     * Double holiday resolves to DH (no rest day) and DHRD (rest day).
     */
    public function test_double_holiday_resolves_to_dh_and_dhrd(): void
    {
        $service = app(PayrollRulesEngineService::class);

        $this->assertSame('DH', $service->resolveRuleCode(false, 'double'));
        $this->assertSame('DHRD', $service->resolveRuleCode(true, 'double'));
    }

    /**
     * Meal break excluded from work: 9h clock span with 1h lunch → 8h net → 0 OT (matches reports / net DTR).
     */
    public function test_segmentation_excludes_scheduled_break_from_ot(): void
    {
        $tz = 'Asia/Manila';
        $date = '2026-03-20';
        $timeIn = Carbon::parse("{$date} 09:00", $tz);
        $timeOut = Carbon::parse("{$date} 18:00", $tz);
        $daySchedule = [
            'in' => '09:00',
            'out' => '18:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
        ];

        $service = app(TimeSegmentationService::class);
        $seg = $service->segment($timeIn, $timeOut, $tz, $daySchedule, $date);

        $this->assertSame(480, $seg['regular_minutes'], '8 net hours = regular');
        $this->assertSame(0, $seg['overtime_minutes'], 'No OT when net work is exactly 8h');
        $this->assertSame(480, $seg['total_minutes'], 'Total work minutes exclude break');
    }

    /**
     * DH rule in config returns first_8=3.0, ot=3.90 per PH Labor Code.
     * (getMultipliersForRule reads from DB first; config is fallback and source of truth.)
     */
    public function test_dh_rule_config_has_correct_multipliers(): void
    {
        $rules = Config::get('payroll.rules', []);
        $this->assertArrayHasKey('DH', $rules, 'DH rule must exist in config');
        $dh = $rules['DH'];
        $this->assertSame(3.0, (float) ($dh['first_8'] ?? 0));
        $this->assertSame(3.9, (float) ($dh['ot'] ?? 0));
        $this->assertSame(3.0, (float) ($dh['nd_base'] ?? 0));
    }

    /**
     * Rendered OT = net work at/after scheduled end (e.g. 5:00 PM). Regular hours are schedule-bounded:
     * early clock-in does not inflate regular — effective window is max(timeIn, scheduleStart) through
     * scheduled end (minus break). 7:03–6:30 PM with 8–5 + 1h lunch → 8h regular + 1.5h OT.
     */
    public function test_rendered_ot_uses_schedule_end_with_break(): void
    {
        Config::set('payroll.ot_basis', 'schedule_end');

        $tz = 'Asia/Manila';
        $date = '2026-03-20';
        $timeIn = Carbon::parse("{$date} 07:03", $tz);
        $timeOut = Carbon::parse("{$date} 18:30", $tz);
        $daySchedule = [
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
        ];

        $service = app(TimeSegmentationService::class);
        $seg = $service->segment($timeIn, $timeOut, $tz, $daySchedule, $date);

        $this->assertSame(90, $seg['overtime_minutes'], 'OT = work at/after 5:00 PM → 1.5h');
        $this->assertSame(480, $seg['regular_minutes'], '8h regular within 08:00–17:00 minus lunch (early hour ignored)');
        $this->assertSame(570, $seg['total_minutes'], 'Net paid work = regular + OT');
    }
}
