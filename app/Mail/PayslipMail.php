<?php

namespace App\Mail;

use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayslipMail extends Mailable
{
    use Queueable, SerializesModels;

    public $payslip;

    public function __construct(Payslip $payslip)
    {
        $this->payslip = $payslip;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Payslip for ' . $this->payslip->period,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payslip',
        );
    }

    public function attachments(): array
    {
        $pdf = Pdf::loadView('pdf.payslip', ['payslip' => $this->payslip]);
        return [
            Attachment::fromData(fn() => $pdf->output(), 'payslip-' . $this->payslip->period . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
