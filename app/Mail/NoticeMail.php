<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class NoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public array $data,
        public ?string $replyToAddress = null,
        public ?string $messageId = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notice', with: $this->data);
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->messageId);
    }
}
