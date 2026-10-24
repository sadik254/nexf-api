<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $code;
    public string $type;

    public function __construct(string $code, string $type, public ?string $email = null)
    {
        $this->code = $code;
        $this->type = $type;
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->type) {
            'password_reset' => 'Password Reset Code',
            'email_change_current', 'email_change_new' => 'Email Change Code',
            default => 'Email Verification Code',
        };

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-verification-code',
        );
    }
}
