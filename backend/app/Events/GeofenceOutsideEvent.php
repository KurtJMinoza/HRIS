<?php

namespace App\Events;

class GeofenceOutsideEvent extends GeofenceMonitorEvent
{
    public function broadcastAs(): string
    {
        return 'geofence.outside';
    }
}
