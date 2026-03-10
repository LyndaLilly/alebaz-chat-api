<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class MessageUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public Message $message;
    public array $recipientIds;

    public function __construct(Message $message, array $recipientIds)
    {
        $this->message = $message;
        $this->recipientIds = $recipientIds;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('conversation.' . $this->message->conversation_id);
    }

    public function broadcastAs()
    {
        return 'message.updated';
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'body' => $this->message->body,
            'message_type' => $this->message->message_type,
            'edited_at' => $this->message->edited_at,
            'deleted_at' => $this->message->deleted_at,
            'deleted_by' => $this->message->deleted_by,
            'created_at' => $this->message->created_at,
        ];
    }
}