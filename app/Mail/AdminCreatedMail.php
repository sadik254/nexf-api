<?php

namespace App\Mail;

use App\Models\Admin;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Admin $admin, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Create your admin password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-created');
    }
}
