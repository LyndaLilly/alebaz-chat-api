<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'message_type',
        'audio_path',
        'audio_duration',

        'file_path',
        'file_name',
        'file_mime',
        'file_size',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(Client::class, 'sender_id');
    }
}
