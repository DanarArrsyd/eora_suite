<?php

declare(strict_types=1);

namespace App\Platform\Auth\Delivery;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Password-reset email (doc 25 §2.3): one-time secret only in the URL fragment; sent synchronously, never queued. */
final class PasswordResetMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your EORA Suite password');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reset', with: ['link' => $this->link]);
    }
}
