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

    public function __construct(string $code, string $type)
    {
        $this->code = $code;
        $this->type = $type;
    }

    public function envelope(): Envelope
    {
        $subject = $this->type === 'password_reset'
            ? 'Password Reset Code'
            : 'Email Verification Code';

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
