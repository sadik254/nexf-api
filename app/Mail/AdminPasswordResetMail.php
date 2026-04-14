<?php

namespace App\Mail;

use App\Models\Admin;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public Admin $admin;
    public string $password;

    public function __construct(Admin $admin, string $password)
    {
        $this->admin = $admin;
        $this->password = $password;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Admin Password Reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-password-reset',
        );
    }
}
