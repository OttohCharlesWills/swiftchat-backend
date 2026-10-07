<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One event for everything call-related, sent to one person's private
 * channel "user.{id}":
 *   call.incoming  -> your phone should ring
 *   call.updated   -> the call changed (accepted / declined / missed / cancelled / ended)
 *   call.signal    -> WebRTC handshake data from the other phone (offer / answer / candidate)
 */
class CallSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public string $event,
        public array $payload,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
