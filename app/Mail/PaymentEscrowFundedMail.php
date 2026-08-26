<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentEscrowFundedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public float  $amount,
        public float  $fee,
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
            subject: "Payment Confirmed — {$this->currency} " . number_format($this->amount, 2),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.payment.escrow_funded', [
                'userName'       => $this->userName,
                'amount'         => $this->amount,
                'fee'            => $this->fee,
                'currency'       => $this->currency,
                'reference'      => $this->reference,
                'milestoneTitle' => $this->milestoneTitle,
                'contractTitle'  => $this->contractTitle,
                'date'           => $this->date,
                'dashboardUrl'   => $this->dashboardUrl,
                'subject'        => "Payment Confirmed — {$this->currency} " . number_format($this->amount, 2),
                'headerSubtitle' => 'Escrow Funding Confirmation',
            ]),
        );
    }
}
