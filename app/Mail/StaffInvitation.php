<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public $staff;
    public $employer;
    public $inviteLink;

    public function __construct(User $staff, User $employer, string $inviteLink)
    {
        $this->staff = $staff;
        $this->employer = $employer;
        $this->inviteLink = $inviteLink;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->employer->company_name . ' invited you to SalaryNowNow',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-invitation',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
