<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'type',
        'created_by',
    ];

    public function creator()
    {
        return $this->belongsTo(Client::class, 'created_by');
    }

    public function participants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    // the people in the conversation (clients)
    public function clients()
    {
        return $this->belongsToMany(Client::class, 'conversation_participants', 'conversation_id', 'user_id')
            ->withPivot(['role', 'last_read_message_id']);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
public function latestMessage()
{
    return $this->hasOne(\App\Models\Message::class)
        ->latestOfMany()
        ->select('messages.*'); 
}
}
