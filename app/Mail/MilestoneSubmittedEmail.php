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
 * Email to the EMPLOYER when a freelancer submits a milestone for review.
 */
class MilestoneSubmittedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $userName,
        public string $milestoneTitle,
        public string $contractTitle,
        public int $contractId,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "Milestone \"{$this->milestoneTitle}\" submitted for your review",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.general.milestone_submitted',
            with: [
                'userName'       => $this->userName,
                'milestoneTitle' => $this->milestoneTitle,
                'contractTitle'  => $this->contractTitle,
                'date'           => now()->format('M j, Y'),
                'dashboardUrl'   => rtrim((string) config('app.frontend_url'), '/')
                    ."/employer/contracts/{$this->contractId}",
            ],
        );
    }
}
