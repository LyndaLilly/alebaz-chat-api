<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class ClientVerificationMail extends Mailable
{
    public string $email;
    public string $code;
    public int $expiresInMinutes;

    public function __construct(string $email, string $code, int $expiresInMinutes = 10)
    {
        $this->email = $email;
        $this->code = $code;
        $this->expiresInMinutes = $expiresInMinutes;
    }

    public function build()
    {
        return $this->subject('Your Verification Code')
            ->view('emails.client_verification');
    }
}
