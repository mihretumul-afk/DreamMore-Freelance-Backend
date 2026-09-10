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
 * Email to the EMPLOYER when a freelancer submits a proposal on their job.
 * Uses the general notification layout (emails/layouts/notification).
 */
class NewProposalEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $userName,
        public string $freelancerName,
        public string $jobTitle,
        public int $jobId,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "New proposal on \"{$this->jobTitle}\"",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.general.new_proposal',
            with: [
                'userName'       => $this->userName,
                'freelancerName' => $this->freelancerName,
                'jobTitle'       => $this->jobTitle,
                'date'           => now()->format('M j, Y'),
                'dashboardUrl'   => rtrim((string) config('app.frontend_url'), '/')
                    ."/employer/jobs/{$this->jobId}/proposals",
            ],
        );
    }
}
