<?php

namespace App\Events;

class AttendanceClockEvent extends ScopedRealtimeEvent
{
    public function broadcastAs(): string
    {
        return 'attendance.clock_event';
    }
}
