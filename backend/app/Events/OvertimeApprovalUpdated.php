<?php

namespace App\Events;

class OvertimeApprovalUpdated extends ScopedRealtimeEvent
{
    public function broadcastAs(): string
    {
        return 'overtime.approval_updated';
    }
}
