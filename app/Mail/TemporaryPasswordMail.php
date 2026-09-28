<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers the temporary password for an account an admin just created (venue owner
 * or admin), mirroring the OtpMail pattern already used for registration/reset codes.
 */
class TemporaryPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $password,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Playvo Account Credentials',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.temporary_password',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
