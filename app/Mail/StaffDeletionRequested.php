<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffDeletionRequested extends Mailable
{
    use Queueable, SerializesModels;

    public $staff;
    public $requester;
    public $approverName;
    public $reason;

    public function __construct(User $staff, ?User $requester = null, ?string $approverName = null, ?string $reason = null)
    {
        $this->staff = $staff;
        $this->requester = $requester;
        $this->approverName = $approverName;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Staff Deletion Awaiting Your Approval',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-deletion-requested',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
