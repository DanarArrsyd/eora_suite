<?php

declare(strict_types=1);

namespace App\Platform\Auth\Delivery;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Invitation email (doc 25 §2.4). The one-time secret travels only in the URL fragment, so it never reaches server
 * logs or Referer headers. Sent synchronously by the delivery worker, never queued (the secret must not be persisted).
 */
final class InvitationMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You are invited to EORA Suite');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invitation', with: ['link' => $this->link]);
    }
}
