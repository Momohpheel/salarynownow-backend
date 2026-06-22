<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminCreated extends Mailable
{
    use Queueable, SerializesModels;

    public $admin;
    public $password;

    public function __construct(User $admin, string $password)
    {
        $this->admin = $admin;
        $this->password = $password;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to SalaryNowNow - Admin Account Created',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-created',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
