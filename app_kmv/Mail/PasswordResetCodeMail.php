<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $code,
        public readonly string $expiresAtText,
        public readonly string $appName
    ) {
    }

    public function build(): self
    {
        return $this->subject($this->appName . ' Password Reset Code')
            ->view('emails.password-reset-code');
    }
}
