<?php
namespace App\Mail;
use Illuminate\Bus\Queueable;use Illuminate\Mail\Mailable;use Illuminate\Mail\Mailables\Content;use Illuminate\Mail\Mailables\Envelope;use Illuminate\Queue\SerializesModels;
class StaffInvitedMail extends Mailable {use Queueable,SerializesModels;public function __construct(public string $name,public string $email,public string $storeName,public string $code){} public function envelope():Envelope{return new Envelope(subject:"You've been added to {$this->storeName}");}public function content():Content{return new Content(view:'emails.staff-invited');}}
