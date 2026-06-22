<?php

namespace App\Events;

class GeofenceSkippedEvent extends GeofenceMonitorEvent
{
    public function broadcastAs(): string
    {
        return 'geofence.skipped';
    }
}
