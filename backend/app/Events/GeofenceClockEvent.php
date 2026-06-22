<?php

namespace App\Events;

class GeofenceClockEvent extends GeofenceMonitorEvent
{
    public function broadcastAs(): string
    {
        return 'geofence.clock';
    }
}
