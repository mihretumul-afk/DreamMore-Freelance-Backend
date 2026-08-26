<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MilestonePaidMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public float  $amount,
        public float  $fee,
        public float  $netAmount,
        public string $currency,
        public string $reference,
        public string $milestoneTitle,
        public string $contractTitle,
        public string $date,
        public string $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment Received — {$this->currency} " . number_format($this->netAmount, 2),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.payment.milestone_paid', [
                'userName'       => $this->userName,
                'amount'         => $this->amount,
                'fee'            => $this->fee,
                'netAmount'      => $this->netAmount,
                'currency'       => $this->currency,
                'reference'      => $this->reference,
                'milestoneTitle' => $this->milestoneTitle,
                'contractTitle'  => $this->contractTitle,
                'date'           => $this->date,
                'dashboardUrl'   => $this->dashboardUrl,
                'subject'        => "Payment Received — {$this->currency} " . number_format($this->netAmount, 2),
                'headerSubtitle' => 'Milestone Payment Released',
            ]),
        );
    }
}
