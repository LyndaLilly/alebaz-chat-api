<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    return Conversation::query()
        ->where('id', $conversationId)
        ->whereHas('participants', fn($q) => $q->where('user_id', $user->id))
        ->exists();
});

Broadcast::channel('client.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
