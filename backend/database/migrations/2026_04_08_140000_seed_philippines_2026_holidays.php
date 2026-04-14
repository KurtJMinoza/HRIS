<?php

use App\Models\Holiday;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * National holidays for the Philippines (2026 proclamation-aligned dates) with recurring annual application.
 *
 * Types: regular | special (non-working) | special_working
 * Payroll: {@see config('payroll.holiday_types')}, {@see PayrollComputationService::computeDayPayroll()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holidays')) {
            return;
        }

        $rows = [
            ['2026-01-01', "New Year's Day", 'regular'],
            ['2026-04-02', 'Maundy Thursday', 'regular'],
            ['2026-04-03', 'Good Friday', 'regular'],
            ['2026-04-09', 'Araw ng Kagitingan (Day of Valor)', 'regular'],
            ['2026-05-01', 'Labor Day', 'regular'],
            ['2026-06-12', 'Independence Day', 'regular'],
            ['2026-08-31', 'National Heroes Day', 'regular'],
            ['2026-11-30', 'Bonifacio Day', 'regular'],
            ['2026-12-25', 'Christmas Day', 'regular'],
            ['2026-12-30', 'Rizal Day', 'regular'],
            ['2026-02-17', 'Chinese New Year', 'special'],
            ['2026-04-04', 'Black Saturday', 'special'],
            ['2026-08-21', 'Ninoy Aquino Day', 'special'],
            ['2026-11-01', "All Saints' Day", 'special'],
            ['2026-11-02', "All Souls' Day", 'special'],
            ['2026-12-08', 'Feast of the Immaculate Conception', 'special'],
            ['2026-12-24', 'Christmas Eve', 'special'],
            ['2026-12-31', 'Last Day of the Year', 'special'],
            ['2026-02-25', 'EDSA People Power Revolution Anniversary', 'special_working'],
        ];

        foreach ($rows as [$date, $name, $type]) {
            Holiday::query()->updateOrCreate(
                ['date' => $date],
                [
                    'name' => $name,
                    'type' => $type,
                    'scope' => 'nationwide',
                    'description' => null,
                    'is_recurring' => true,
                    'status' => 'active',
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('holidays')) {
            return;
        }

        $dates = [
            '2026-01-01', '2026-04-02', '2026-04-03', '2026-04-09', '2026-05-01', '2026-06-12',
            '2026-08-31', '2026-11-30', '2026-12-25', '2026-12-30',
            '2026-02-17', '2026-04-04', '2026-08-21', '2026-11-01', '2026-11-02', '2026-12-08',
            '2026-12-24', '2026-12-31', '2026-02-25',
        ];
        Holiday::query()->whereIn('date', $dates)->delete();
    }
};
