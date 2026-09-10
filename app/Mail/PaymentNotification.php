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
 * Base class for payment-related emails that reuse the existing
 * resources/views/emails/payment/* blade templates and the
 * emails.layouts.payment HTML shell.
 *
 * All payment emails are queued (never sent synchronously) so a slow
 * mail provider can never block a user's request. Failures are retried
 * by the queue worker and end up in failed_jobs — they never bubble
 * into the user-facing request.
 */
abstract class PaymentNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $userName,
        public ?string $dashboardUrl = null,
    ) {
        // Deliberately on the default queue: ops runs a single plain
        // `queue:work` worker (see README). A dedicated queue would need
        // explicit --queue flags or these emails would never be sent.
    }

    abstract protected function subjectLine(): string;

    abstract protected function viewName(): string;

    /**
     * Extra template data specific to each email, merged on top of the
     * shared data every payment email receives.
     *
     * @return array<string, mixed>
     */
    protected function templateData(): array
    {
        return [];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: $this->viewName(),
            with: array_merge([
                'userName'     => $this->userName,
                'currency'     => 'ETB',
                'date'         => now()->format('M j, Y'),
                'dashboardUrl' => $this->dashboardUrl
                    ?? rtrim((string) config('app.frontend_url'), '/').'/freelancer/earnings',
            ], $this->templateData()),
        );
    }
}
