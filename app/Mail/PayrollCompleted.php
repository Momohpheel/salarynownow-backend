<?php

namespace App\Mail;

use App\Models\Payroll;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayrollCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public $payroll;

    public function __construct(Payroll $payroll)
    {
        $this->payroll = $payroll;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payroll Processed Successfully',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payroll-completed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
