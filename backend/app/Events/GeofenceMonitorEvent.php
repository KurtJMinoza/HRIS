<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class GeofenceMonitorEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'realtime';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [];
        $companyId = $this->payload['company_id'] ?? null;
        if ($companyId !== null) {
            $channels[] = new PrivateChannel('geofence-monitor.company.'.(int) $companyId);
        }

        $channels[] = new PrivateChannel('geofence-monitor.global');

        return $channels;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
