<?php
namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderNotificationMail extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public Order $order, public string $subjectLine, public string $messageLine) {}
    public function envelope(): Envelope { return new Envelope(subject: $this->subjectLine); }
    public function content(): Content { return new Content(view: 'emails.order-notification'); }
}
