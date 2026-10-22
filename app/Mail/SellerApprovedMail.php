<?php

namespace App\Mail;

use App\Models\Seller;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SellerApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Seller $seller, public ?string $code = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Seller Account Has Been Approved');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.seller-approved');
    }
}
