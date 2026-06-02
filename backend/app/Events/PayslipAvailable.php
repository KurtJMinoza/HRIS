<?php

namespace App\Events;

class PayslipAvailable extends ScopedRealtimeEvent
{
    public function broadcastAs(): string
    {
        return 'payslip.available';
    }
}
