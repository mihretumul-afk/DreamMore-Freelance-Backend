<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string  $userName,
        public float   $amount,
        public float   $fee,
        public float   $netAmount,
        public string  $currency,
        public string  $reference,
        public ?string $methodName,
        public string  $date,
        public string  $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Withdrawal Requested — {$this->currency} " . number_format($this->amount, 2),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.payment.withdrawal_requested', [
                'userName'     => $this->userName,
                'amount'       => $this->amount,
                'fee'          => $this->fee,
                'netAmount'    => $this->netAmount,
                'currency'     => $this->currency,
                'reference'    => $this->reference,
                'methodName'   => $this->methodName,
                'date'         => $this->date,
                'dashboardUrl' => $this->dashboardUrl,
                'subject'      => "Withdrawal Requested — {$this->currency} " . number_format($this->amount, 2),
                'headerSubtitle' => 'Withdrawal Request Received',
            ]),
        );
    }
}
