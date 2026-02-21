<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'clients';

    protected $fillable = [
        'email',
        'phone',
        'username',
        'profile_image',
        'pin',

        'email_verification_code',
        'email_verification_expires_at',
        'email_verification_last_sent_at',
        'email_verification_resend_count',
        'email_verified_at',

        'phone_verification_code',
        'phone_verified_at',

        'verified',
        'account_completed',
        'onboarding_step',
    ];

    protected $hidden = [
        'pin',
        'email_verification_code',
        'phone_verification_code',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',

        'verified'          => 'boolean',
        'account_completed' => 'boolean',
        'onboarding_step'   => 'integer',
    ];

    public function conversationParticipants()
    {
        return $this->hasMany(\App\Models\ConversationParticipant::class, 'user_id');
    }

    public function conversations()
    {
        return $this->belongsToMany(
            \App\Models\Conversation::class,
            'conversation_participants',
            'user_id',
            'conversation_id'
        )->withPivot(['role', 'last_read_message_id']);
    }

    public function messages()
    {
        return $this->hasMany(\App\Models\Message::class, 'sender_id');
    }

}
