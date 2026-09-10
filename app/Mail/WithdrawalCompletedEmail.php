<?php

namespace App\Mail;

/**
 * Email to the FREELANCER when their withdrawal has been successfully
 * processed and funds sent. Reuses emails/payment/withdrawal_completed.
 */
class WithdrawalCompletedEmail extends PaymentNotification
{
    public function __construct(
        string $userName,
        public string $reference,
        public float $amount,
        public float $fee,
        public float $netAmount,
        public ?string $methodName = null,
        ?string $dashboardUrl = null,
    ) {
        parent::__construct($userName, $dashboardUrl);
    }

    protected function subjectLine(): string
    {
        return "Withdrawal completed — ETB ".number_format($this->netAmount, 2)." sent to your account";
    }

    protected function viewName(): string
    {
        return 'emails.payment.withdrawal_completed';
    }

    protected function templateData(): array
    {
        return [
            'reference'    => $this->reference,
            'amount'       => $this->amount,
            'fee'          => $this->fee,
            'netAmount'    => $this->netAmount,
            'methodName'   => $this->methodName,
            'dashboardUrl' => $this->dashboardUrl
                ?? rtrim((string) config('app.frontend_url'), '/').'/freelancer/earnings',
        ];
    }
}
