<?php

namespace App\Mail;

/**
 * Email to the FREELANCER when the employer approves a milestone and the
 * escrow payment is released to their wallet. Reuses
 * emails/payment/milestone_paid.
 */
class MilestonePaidEmail extends PaymentNotification
{
    public function __construct(
        string $userName,
        public string $reference,
        public string $milestoneTitle,
        public string $contractTitle,
        public int $contractId,
        public float $amount,
        public float $fee,
        public float $netAmount,
        ?string $dashboardUrl = null,
    ) {
        parent::__construct($userName, $dashboardUrl);
    }

    protected function subjectLine(): string
    {
        return "Payment received — {$this->milestoneTitle} milestone approved 🎉";
    }

    protected function viewName(): string
    {
        return 'emails.payment.milestone_paid';
    }

    protected function templateData(): array
    {
        return [
            'reference'      => $this->reference,
            'milestoneTitle' => $this->milestoneTitle,
            'contractTitle'  => $this->contractTitle,
            'amount'         => $this->amount,
            'fee'            => $this->fee,
            'netAmount'      => $this->netAmount,
            'dashboardUrl'   => $this->dashboardUrl
                ?? rtrim((string) config('app.frontend_url'), '/')."/freelancer/contracts/{$this->contractId}",
        ];
    }
}
