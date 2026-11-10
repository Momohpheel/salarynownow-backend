<?php

namespace App\Mail;

use App\Models\Payroll;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayrollPendingApproval extends Mailable
{
    use Queueable, SerializesModels;

    public $payroll;
    public $requester;
    public $approverName;

    public function __construct(Payroll $payroll, ?User $requester = null, ?string $approverName = null)
    {
        $this->payroll = $payroll;
        $this->requester = $requester;
        $this->approverName = $approverName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payroll Awaiting Your Approval',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payroll-pending-approval',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
