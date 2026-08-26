<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RefundCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string  $userName,
        public float   $amount,
        public string  $currency,
        public string  $originalReference,
        public ?string $reason,
        public string  $date,
        public string  $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Refund Processed — {$this->currency} " . number_format($this->amount, 2),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.payment.refund_completed', [
                'userName'           => $this->userName,
                'amount'             => $this->amount,
                'currency'           => $this->currency,
                'originalReference'  => $this->originalReference,
                'reason'             => $this->reason,
                'date'               => $this->date,
                'dashboardUrl'       => $this->dashboardUrl,
                'subject'            => "Refund Processed — {$this->currency} " . number_format($this->amount, 2),
                'headerSubtitle'     => 'Refund Confirmation',
            ]),
        );
    }
}
