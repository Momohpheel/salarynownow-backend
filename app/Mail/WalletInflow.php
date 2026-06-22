<?php

namespace App\Mail;

use App\Models\WalletLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WalletInflow extends Mailable
{
    use Queueable, SerializesModels;

    public $walletLog;
    public $user;

    public function __construct(WalletLog $walletLog, $user)
    {
        $this->walletLog = $walletLog;
        $this->user = $user;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wallet Topup Successful',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.wallet-inflow',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
