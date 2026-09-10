<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email to the message RECIPIENT when they are offline (not connected
 * to their chat channel) and a new message arrives. Queued with a 60s
 * delay so rapid-fire messages collapse into one digest-like email.
 */
class NewMessageEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $userName,
        public string $senderName,
        public string $messagePreview,
    ) {
        $this->delay(60);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "New message from {$this->senderName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.general.new_message',
            with: [
                'userName'       => $this->userName,
                'senderName'     => $this->senderName,
                'messagePreview' => $this->messagePreview,
                'date'           => now()->format('M j, Y'),
                'dashboardUrl'   => rtrim((string) config('app.frontend_url'), '/').'/freelancer/messages',
            ],
        );
    }
}
