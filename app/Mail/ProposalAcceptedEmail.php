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
 * Email to the FREELANCER when their proposal is accepted and a contract
 * offer has been created for their review.
 */
class ProposalAcceptedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $userName,
        public string $jobTitle,
        public ?int $contractId = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "Your proposal for \"{$this->jobTitle}\" was accepted 🎉",
        );
    }

    public function content(): Content
    {
        $url = $this->contractId
            ? rtrim((string) config('app.frontend_url'), '/')."/freelancer/contracts/{$this->contractId}"
            : rtrim((string) config('app.frontend_url'), '/').'/freelancer/contracts';

        return new Content(
            view: 'emails.general.proposal_accepted',
            with: [
                'userName'     => $this->userName,
                'jobTitle'     => $this->jobTitle,
                'date'         => now()->format('M j, Y'),
                'dashboardUrl' => $url,
            ],
        );
    }
}
