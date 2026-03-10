<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // ✅ change
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallIncoming implements ShouldBroadcastNow // ✅ change
{
    use Dispatchable, SerializesModels;

    public array $payload;
    private int $toUserId;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->toUserId = (int) ($payload['to_user_id'] ?? 0);
    }

    public function broadcastOn()
    {
        return new PrivateChannel('client.' . $this->toUserId);
    }

    public function broadcastAs()
    {
        return 'call.incoming';
    }
}