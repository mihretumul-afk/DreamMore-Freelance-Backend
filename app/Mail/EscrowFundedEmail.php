<?php

namespace App\Mail;

/**
 * Email to the EMPLOYER when their escrow payment for a milestone is
 * confirmed (funds held in escrow). Reuses emails/payment/escrow_funded.
 */
class EscrowFundedEmail extends PaymentNotification
{
    public function __construct(
        string $userName,
        public string $reference,
        public string $milestoneTitle,
        public string $contractTitle,
        public int $contractId,
        public float $amount,
        public float $fee,
        ?string $dashboardUrl = null,
    ) {
        parent::__construct($userName, $dashboardUrl);
    }

    protected function subjectLine(): string
    {
        return "Payment confirmed — escrow funded for \"{$this->milestoneTitle}\"";
    }

    protected function viewName(): string
    {
        return 'emails.payment.escrow_funded';
    }

    protected function templateData(): array
    {
        return [
            'reference'      => $this->reference,
            'milestoneTitle' => $this->milestoneTitle,
            'contractTitle'  => $this->contractTitle,
            'amount'         => $this->amount,
            'fee'            => $this->fee,
            'dashboardUrl'   => $this->dashboardUrl
                ?? rtrim((string) config('app.frontend_url'), '/')."/employer/contracts/{$this->contractId}",
        ];
    }
}
