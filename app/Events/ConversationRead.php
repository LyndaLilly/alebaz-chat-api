<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;
    public array $recipientIds;

    public function __construct(int $conversationId, int $readerId, int $lastReadMessageId, array $recipientIds = [])
    {
        $this->recipientIds = $recipientIds;

        $this->payload = [
            'conversation_id'       => $conversationId,
            'reader_id'             => $readerId,
            'last_read_message_id'  => $lastReadMessageId,
        ];
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('conversation.' . $this->payload['conversation_id']),
        ];

        foreach ($this->recipientIds as $id) {
            $channels[] = new PrivateChannel('client.' . $id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}