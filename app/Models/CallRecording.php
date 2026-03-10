<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallRecording extends Model
{
    protected $table = 'call_recordings';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'audio_path',
        'duration',
    ];
}