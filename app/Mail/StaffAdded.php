<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffAdded extends Mailable
{
    use Queueable, SerializesModels;

    public $staff;
    public $employer;
    public $password;
    public $loginUrl;

    public function __construct(User $staff, User $employer, string $password, string $loginUrl)
    {
        $this->staff = $staff;
        $this->employer = $employer;
        $this->password = $password;
        $this->loginUrl = $loginUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Sugar Payroll - Staff Account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-added',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
