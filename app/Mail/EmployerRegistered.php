<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployerRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public $employer;

    public function __construct(User $employer)
    {
        $this->employer = $employer;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to SalaryNowNow - Complete Your Profile',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employer-registered',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
