<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamMemberAdded extends Mailable
{
    use Queueable, SerializesModels;

    public $teamMember;
    public $employer;
    public $password;

    public function __construct(User $teamMember, User $employer, string $password)
    {
        $this->teamMember = $teamMember;
        $this->employer = $employer;
        $this->password = $password;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to SalaryNowNow - Team Member Account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.team-member-added',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
