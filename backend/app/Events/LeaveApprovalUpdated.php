<?php

namespace App\Events;

class LeaveApprovalUpdated extends ScopedRealtimeEvent
{
    public function broadcastAs(): string
    {
        return 'leave.approval_updated';
    }
}
