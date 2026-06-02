<?php

namespace App\Events;

class AttendanceCorrectionUpdated extends ScopedRealtimeEvent
{
    public function broadcastAs(): string
    {
        return 'attendance_correction.updated';
    }
}
