<?php

namespace App\Events;

class PayrollStatusUpdated extends ScopedRealtimeEvent
{
    public function broadcastAs(): string
    {
        return 'payroll.status_updated';
    }
}
