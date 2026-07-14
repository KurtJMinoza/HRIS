<?php

namespace Tests\Unit;

use App\Services\EfficiencyPeriodResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

class EfficiencyPeriodResolverTest extends TestCase
{
    public function test_this_week_and_this_month_do_not_extend_past_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 14:00:00', 'Asia/Manila'));
        config(['attendance.timezone' => 'Asia/Manila', 'attendance.first_day_of_week' => Carbon::MONDAY]);

        $resolver = app(EfficiencyPeriodResolver::class);

        $week = $resolver->resolve(Request::create('/?period=this_week', 'GET'));
        $this->assertSame('2026-07-13', $week['start_date']);
        $this->assertSame('2026-07-14', $week['end_date']);

        $month = $resolver->resolve(Request::create('/?period=this_month', 'GET'));
        $this->assertSame('2026-07-01', $month['start_date']);
        $this->assertSame('2026-07-14', $month['end_date']);

        Carbon::setTestNow();
    }
}
