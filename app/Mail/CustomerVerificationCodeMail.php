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
            'password_reset' => 'Your NEXF password reset code',
            'email_change_current' => 'Your NEXF security code',
            'email_change_new' => 'Your NEXF verification code',
            default => 'Your NEXF verification code',
        };

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-verification-code',
            text: 'emails.code-text',
        );
    }
}
