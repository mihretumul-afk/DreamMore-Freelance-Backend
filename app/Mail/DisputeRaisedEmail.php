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
 * Email to both contract parties + relevant admins when a dispute is
 * raised on a milestone/contract. Uses a new general notification
 * layout (emails/layouts/notification + emails/general/dispute_raised)
 * because this is not a payment-amount email.
 */
class DisputeRaisedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $userName,
        public string $contractTitle,
        public int $contractId,
        public string $milestoneTitle,
        public string $reason,
        public string $role,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "Dispute raised on contract \"{$this->contractTitle}\"",
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.general.dispute_raised',
            with: [
                'userName'       => $this->userName,
                'contractTitle'  => $this->contractTitle,
                'milestoneTitle' => $this->milestoneTitle,
                'reason'         => $this->reason,
                'role'           => $this->role,
                'date'           => now()->format('M j, Y'),
                'dashboardUrl'   => $this->role === 'admin'
                    ? $base.'/admin/reports'
                    : $base."/{$this->role}/contracts/{$this->contractId}",
            ],
        );
    }
}
