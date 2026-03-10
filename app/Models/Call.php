<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Call extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'conversation_id',
        'from_user_id',
        'to_user_id',
        'type',
        'status',
        'started_at',
        'ended_at',
        'ended_by',
        'end_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
          'in_call_started_at' => 'datetime',
    ];


    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function fromUser()
    {
        return $this->belongsTo(Client::class, 'from_user_id');
    }

    public function toUser()
    {
        return $this->belongsTo(Client::class, 'to_user_id');
    }
}