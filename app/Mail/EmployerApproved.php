<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployerApproved extends Mailable
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
            subject: 'Your SalaryNowNow Account Has Been Approved!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employer-approved',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
