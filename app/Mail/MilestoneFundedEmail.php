<?php

namespace App\Mail;

/**
 * Email to the FREELANCER when an employer funds a milestone's escrow
 * and they can start working. New template emails/payment/milestone_funded.
 */
class MilestoneFundedEmail extends PaymentNotification
{
    public function __construct(
        string $userName,
        public string $milestoneTitle,
        public string $contractTitle,
        public int $contractId,
        public float $amount,
        ?string $dashboardUrl = null,
    ) {
        parent::__construct($userName, $dashboardUrl);
    }

    protected function subjectLine(): string
    {
        return "Milestone funded — you can start working on \"{$this->milestoneTitle}\"";
    }

    protected function viewName(): string
    {
        return 'emails.payment.milestone_funded';
    }

    protected function templateData(): array
    {
        return [
            'milestoneTitle' => $this->milestoneTitle,
            'contractTitle'  => $this->contractTitle,
            'amount'         => $this->amount,
            'dashboardUrl'   => $this->dashboardUrl
                ?? rtrim((string) config('app.frontend_url'), '/')."/freelancer/contracts/{$this->contractId}",
        ];
    }
}
