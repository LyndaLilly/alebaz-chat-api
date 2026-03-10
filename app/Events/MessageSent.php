<?php
namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel; // ✅ changed
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow// ✅ changed

{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;
    public array $recipientIds;

    public function __construct(Message $message, array $recipientIds = [])
    {
        $message->load('sender:id,username,profile_image');

        $this->recipientIds = $recipientIds;

        $this->payload = [
            'id'              => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id'       => $message->sender_id,
            'body'            => $message->body,

            'message_type'    => $message->message_type,
            'audio_path'      => $message->audio_path,
            'audio_url'       => $message->audio_path
                ? (preg_match('/^https?:\/\//i', $message->audio_path) ? $message->audio_path : url($message->audio_path))
                : null,
            'audio_duration'  => $message->audio_duration,

            'file_path'       => $message->file_path,
            'file_url'        => $message->file_path
                ? (preg_match('/^https?:\/\//i', $message->file_path) ? $message->file_path : url($message->file_path))
                : null,
            'file_name'       => $message->file_name,
            'file_mime'       => $message->file_mime,
            'file_size'       => $message->file_size,

            'created_at'      => $message->created_at,
            'sender'          => $message->sender,
        ];
    }

    // ✅ Broadcast to conversation channel + each user's inbox channel
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
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }

}
