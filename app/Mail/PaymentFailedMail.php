<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string  $userName,
        public float   $amount,
        public string  $currency,
        public string  $reference,
        public string  $milestoneTitle,
        public string  $contractTitle,
        public ?string $reason,
        public string  $date,
        public string  $retryUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment Failed — {$this->currency} " . number_format($this->amount, 2),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.payment.payment_failed', [
                'userName'       => $this->userName,
                'amount'         => $this->amount,
                'currency'       => $this->currency,
                'reference'      => $this->reference,
                'milestoneTitle' => $this->milestoneTitle,
                'contractTitle'  => $this->contractTitle,
                'reason'         => $this->reason,
                'date'           => $this->date,
                'retryUrl'       => $this->retryUrl,
                'subject'        => "Payment Failed — {$this->currency} " . number_format($this->amount, 2),
                'headerSubtitle' => 'Payment Processing Error',
            ]),
        );
    }
}
