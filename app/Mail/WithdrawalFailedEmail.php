<?php

namespace App\Mail;

/**
 * Email to the FREELANCER when their withdrawal could not be processed.
 * The reserved amount is returned to their balance. Reuses
 * emails/payment/withdrawal_failed.
 */
class WithdrawalFailedEmail extends PaymentNotification
{
    public function __construct(
        string $userName,
        public string $reference,
        public float $amount,
        public string $reason,
        ?string $dashboardUrl = null,
    ) {
        parent::__construct($userName, $dashboardUrl);
    }

    protected function subjectLine(): string
    {
        return "Action needed — withdrawal {$this->reference} could not be processed";
    }

    protected function viewName(): string
    {
        return 'emails.payment.withdrawal_failed';
    }

    protected function templateData(): array
    {
        return [
            'reference'    => $this->reference,
            'amount'       => $this->amount,
            'reason'       => $this->reason,
            'dashboardUrl' => $this->dashboardUrl
                ?? rtrim((string) config('app.frontend_url'), '/').'/freelancer/withdrawals',
        ];
    }
}
