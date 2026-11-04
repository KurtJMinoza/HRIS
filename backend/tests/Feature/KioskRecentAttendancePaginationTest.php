<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskRecentAttendancePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_kiosk_feed_is_paginated_and_older_punches_stay_reachable(): void
    {
        $base = Carbon::parse('2026-05-08 08:00:00', 'UTC');
        $ids = [];
        foreach (range(0, 6) as $i) {
            $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_active' => true]);
            $ids[] = AttendanceLog::create([
                'user_id' => $user->id,
                'type' => AttendanceLog::TYPE_CLOCK_IN,
                'verified_at' => $base->copy()->addMinutes($i),
            ])->id;
        }
        // Same employee + day + type as the newest punch: deduped away.
        AttendanceLog::create([
            'user_id' => AttendanceLog::find(end($ids))->user_id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'verified_at' => $base->copy()->addMinutes(-5),
        ]);

        $first = $this->getJson('/api/attendance/kiosk/recent?page=1&per_page=5')->assertOk();
        $first->assertJsonPath('meta.total', 7)->assertJsonPath('meta.last_page', 2);
        $this->assertSame(array_reverse(array_slice($ids, 2)), array_column($first->json('attendance'), 'id'));

        $second = $this->getJson('/api/attendance/kiosk/recent?page=2&per_page=5')->assertOk();
        $this->assertSame([$ids[1], $ids[0]], array_column($second->json('attendance'), 'id'));

        $this->getJson('/api/attendance/kiosk/recent?page=99&per_page=5')
            ->assertJsonPath('meta.current_page', 2);
    }
}
