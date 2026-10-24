<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data) {}
    public function envelope(): Envelope { return new Envelope(subject: 'Your account email was changed'); }
    public function content(): Content { return new Content(view: 'emails.email-changed', with: $this->data); }
}
