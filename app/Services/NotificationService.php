<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Mail\MilestonePaidMail;
use App\Mail\PaymentEscrowFundedMail;
use App\Mail\PaymentFailedMail;
use App\Mail\RefundCompletedMail;
use App\Mail\WithdrawalCompletedMail;
use App\Mail\WithdrawalFailedMail;
use App\Mail\WithdrawalRequestedMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Create a notification for a user and broadcast it in real-time.
     */
    public static function create(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $link = null
    ): Notification {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
        ]);

        // Broadcast in real-time via Reverb
        broadcast(new NotificationCreated($notification));

        return $notification;
    }

    /**
     * Notify employer about a new proposal.
     */
    public static function newProposal(int $employerId, string $freelancerName, string $jobTitle, int $jobId): Notification
    {
        return self::create(
            $employerId,
            'new_proposal',
            'New Proposal Received',
            "{$freelancerName} submitted a proposal for '{$jobTitle}'.",
            "/employer/jobs/{$jobId}/proposals"
        );
    }

    /**
     * Notify about proposal status change.
     */
    public static function proposalStatusChanged(int $freelancerId, string $status, string $jobTitle, int $jobId): Notification
    {
        $messages = [
            'shortlisted' => "Your proposal for '{$jobTitle}' has been shortlisted.",
            'rejected' => "Your proposal for '{$jobTitle}' has been rejected.",
            'accepted' => "Your proposal for '{$jobTitle}' has been accepted. A contract has been created.",
        ];

        $links = [
            'shortlisted' => "/freelancer/proposals",
            'rejected' => "/freelancer/proposals",
            'accepted' => "/freelancer/contracts",
        ];

        return self::create(
            $freelancerId,
            'proposal_' . $status,
            'Proposal ' . ucfirst($status),
            $messages[$status] ?? "Your proposal status for '{$jobTitle}' has changed to {$status}.",
            $links[$status] ?? "/freelancer/proposals"
        );
    }

    /**
     * Notify about contract creation.
     */
    public static function contractCreated(int $userId, string $title, string $role): Notification
    {
        $link = $role === 'employer' ? '/employer/contracts' : '/freelancer/contracts';
        $message = $role === 'employer'
            ? "Contract created successfully."
            : "Your proposal was accepted. A contract has been created.";

        return self::create(
            $userId,
            'contract_created',
            'Contract Created',
            $message,
            $link
        );
    }

    /**
     * Notify about milestone submission.
     */
    public static function milestoneSubmitted(int $employerId, string $milestoneTitle, string $contractTitle, int $contractId): Notification
    {
        return self::create(
            $employerId,
            'milestone_submitted',
            'Milestone Submitted',
            "Milestone '{$milestoneTitle}' in contract '{$contractTitle}' has been submitted for review.",
            "/employer/contracts/{$contractId}"
        );
    }

    /**
     * Notify about milestone approval.
     */
    public static function milestoneApproved(int $freelancerId, string $milestoneTitle, string $contractTitle, int $contractId): Notification
    {
        return self::create(
            $freelancerId,
            'milestone_approved',
            'Milestone Approved',
            "Milestone '{$milestoneTitle}' in contract '{$contractTitle}' has been approved.",
            "/freelancer/contracts/{$contractId}"
        );
    }

    /**
     * Notify about milestone revision requested.
     */
    public static function milestoneRevision(int $freelancerId, string $milestoneTitle, string $contractTitle, int $contractId, ?string $revisionNote = null): Notification
    {
        $message = "Milestone '{$milestoneTitle}' in contract '{$contractTitle}' needs revision.";
        if ($revisionNote) {
            $message .= " Feedback: {$revisionNote}";
        }

        return self::create(
            $freelancerId,
            'milestone_revision',
            'Milestone Revision Requested',
            $message,
            "/freelancer/contracts/{$contractId}"
        );
    }

    /**
     * Notify about new message.
     */
    public static function messageReceived(int $receiverId, string $senderName): Notification
    {
        return self::create(
            $receiverId,
            'message_received',
            'New Message',
            "You have a new message from {$senderName}.",
            '/freelancer/messages'
        );
    }

    /**
     * Notify about credential review.
     */
    public static function credentialApproved(int $freelancerId, string $title): Notification
    {
        return self::create(
            $freelancerId,
            'credential_approved',
            'Credential Approved',
            "Your credential '{$title}' has been approved.",
            '/freelancer/profile'
        );
    }

    public static function credentialRejected(int $freelancerId, string $title, ?string $reason = null): Notification
    {
        $message = "Your credential '{$title}' has been rejected.";
        if ($reason) {
            $message .= " Reason: {$reason}";
        }

        return self::create(
            $freelancerId,
            'credential_rejected',
            'Credential Rejected',
            $message,
            '/freelancer/profile'
        );
    }

    /**
     * Notify about a received review.
     */
    public static function reviewReceived(int $revieweeId, string $reviewerName, int $rating, int $contractId): Notification
    {
        return self::create(
            $revieweeId,
            'review_received',
            'New Review Received',
            "{$reviewerName} left you a {$rating}-star review.",
            "/freelancer/profile"
        );
    }

    /**
     * Notify freelancer that their Dream More LMS certificate has been verified.
     */
    public static function lmsCertificateVerified(int $freelancerId, string $courseName, string $certificateId): Notification
    {
        return self::create(
            $freelancerId,
            'lms_certificate_verified',
            'Dream More Certificate Verified',
            "Your Dream More certificate for '{$courseName}' (ID: {$certificateId}) has been verified and added to your credentials.",
            '/freelancer/credentials'
        );
    }

    /**
     * Notify freelancer that their LMS certificate was revoked.
     */
    public static function lmsCertificateRevoked(int $freelancerId, string $courseName, ?string $reason = null): Notification
    {
        $message = "Your Dream More certificate for '{$courseName}' has been revoked.";
        if ($reason) {
            $message .= " Reason: {$reason}";
        }

        return self::create(
            $freelancerId,
            'lms_certificate_revoked',
            'Dream More Certificate Revoked',
            $message,
            '/freelancer/credentials'
        );
    }

    /**
     * Notify freelancer that a skill assessment is required.
     */
    public static function skillTestRequired(int $freelancerId, string $credentialTitle, string $testName): Notification
    {
        return self::create(
            $freelancerId,
            'skill_test_required',
            'Skill Assessment Required',
            "A skill assessment is required for your credential '{$credentialTitle}'. Please complete the '{$testName}' test.",
            '/freelancer/credentials'
        );
    }

    /**
     * Notify freelancer that they passed a skill test.
     */
    public static function credentialTestPassed(int $freelancerId, string $credentialTitle): Notification
    {
        return self::create(
            $freelancerId,
            'credential_test_passed',
            'Skill Assessment Passed',
            "Your skill assessment has been passed. Your credential '{$credentialTitle}' is now verified.",
            '/freelancer/credentials'
        );
    }

    /**
     * Notify all administrators of an administrative event.
     */
    public static function notifyAdmins(string $type, string $title, string $message, ?string $link = null): void
    {
        $admins = \App\Models\User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            self::create($admin->id, $type, $title, $message, $link);
        }
    }

    /**
     * Notify user that their verification has been approved.
     */
    public static function verificationApproved(int $userId, string $role): Notification
    {
        $link = $role === 'employer' ? '/employer/profile' : '/freelancer/profile';

        return self::create(
            $userId,
            'verification_approved',
            'Verification Approved',
            'Your identity and profile verification documents have been approved.',
            $link
        );
    }

    /**
     * Notify user that their verification has been rejected.
     */
    public static function verificationRejected(int $userId, string $role, ?string $reason = null): Notification
    {
        $link = $role === 'employer' ? '/employer/profile' : '/freelancer/profile';
        $message = 'Your verification request was rejected.';
        if ($reason) {
            $message .= " Reason: {$reason}";
        }

        return self::create(
            $userId,
            'verification_rejected',
            'Verification Rejected',
            $message,
            $link
        );
    }

    /**
     * Notify user that contract has been completed.
     */
    public static function contractCompleted(int $userId, string $title, string $role): Notification
    {
        $link = $role === 'employer' ? '/employer/contracts' : '/freelancer/contracts';

        return self::create(
            $userId,
            'contract_completed',
            'Contract Completed',
            "The contract '{$title}' has been successfully completed. You can now leave a review.",
            $link
        );
    }

    /**
     * Notify participants about dispute.
     */
    public static function disputeRaised(int $userId, string $contractTitle, int $contractId, string $role): Notification
    {
        $link = $role === 'employer' ? "/employer/contracts/{$contractId}" : "/freelancer/contracts/{$contractId}";

        return self::create(
            $userId,
            'dispute_raised',
            'Dispute Raised',
            "A dispute has been raised on contract '{$contractTitle}'. An administrator will review it.",
            $link
        );
    }

    /**
     * Notify participants about dispute resolution.
     */
    public static function disputeResolved(int $userId, string $contractTitle, int $contractId, string $role, string $status): Notification
    {
        $link = $role === 'employer' ? "/employer/contracts/{$contractId}" : "/freelancer/contracts/{$contractId}";

        return self::create(
            $userId,
            'dispute_resolved',
            'Dispute Resolved',
            "The dispute on contract '{$contractTitle}' has been resolved. Contract status is now {$status}.",
            $link
        );
    }

    /**
     * Notify freelancer that their profile has been approved.
     */
    public static function freelancerApproved(int $freelancerId): Notification
    {
        return self::create(
            $freelancerId,
            'freelancer_approved',
            'Profile Approved',
            'Your freelancer profile has been approved! You are now visible on the marketplace and can submit proposals.',
            '/freelancer/dashboard'
        );
    }

    /**
     * Notify freelancer that their profile has been rejected.
     */
    public static function freelancerRejected(int $freelancerId, ?string $reason = null): Notification
    {
        $message = 'Your freelancer profile application has been rejected.';
        if ($reason) {
            $message .= " Reason: {$reason}";
        }
        $message .= ' Please update your profile and submit again.';

        return self::create(
            $freelancerId,
            'freelancer_rejected',
            'Profile Rejected',
            $message,
            '/freelancer/profile'
        );
    }

    /**
     * Notify admins about new freelancer registration.
     */
    public static function newFreelancerRegistered(int $freelancerId, string $freelancerName): void
    {
        self::notifyAdmins(
            'new_freelancer_registration',
            'New Freelancer Registration',
            "{$freelancerName} has registered as a freelancer and is awaiting approval.",
            '/admin/freelancers?status=pending'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // PAYMENT notifications
    // ═══════════════════════════════════════════════════════════════════

    // ═══════════════════════════════════════════════════════════════════
    // PAYMENT notifications
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Notify freelancer that a milestone payment has been released to them.
     */
    public static function milestonePaid(int $freelancerId, string $milestoneTitle, int $contractId, float $amount): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        $notification = self::create(
            $freelancerId,
            'milestone_paid',
            'Payment Released',
            "You received {$formatted} for completing milestone \"{$milestoneTitle}\".",
            "/freelancer/contracts/{$contractId}"
        );

        // Send email notification
        self::sendPaymentEmail($freelancerId, 'milestone_paid', [
            'milestoneTitle' => $milestoneTitle,
            'contractId'     => $contractId,
            'amount'         => $amount,
        ]);

        return $notification;
    }

    /**
     * Notify employer that their escrow funding was successful.
     */
    public static function escrowFunded(int $employerId, string $milestoneTitle, int $contractId, float $amount): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        $notification = self::create(
            $employerId,
            'escrow_funded',
            'Escrow Funded',
            "{$formatted} has been held in escrow for milestone \"{$milestoneTitle}\".",
            "/employer/contracts/{$contractId}"
        );

        // Send email notification
        self::sendPaymentEmail($employerId, 'escrow_funded', [
            'milestoneTitle' => $milestoneTitle,
            'contractId'     => $contractId,
            'amount'         => $amount,
        ]);

        return $notification;
    }

    /**
     * Notify user that a refund has been issued.
     */
    public static function paymentRefunded(int $userId, string $reference, float $amount, string $currency = 'ETB'): Notification
    {
        $formatted = "{$currency} " . number_format($amount, 2);
        $notification = self::create(
            $userId,
            'payment_refunded',
            'Refund Issued',
            "A refund of {$formatted} has been issued for payment {$reference}.",
            '/freelancer/payments'
        );

        // Send email notification
        self::sendPaymentEmail($userId, 'refund_completed', [
            'reference' => $reference,
            'amount'    => $amount,
            'currency'  => $currency,
        ]);

        return $notification;
    }

    /**
     * Notify user that a payment has failed.
     */
    public static function paymentFailed(int $userId, string $reference, float $amount, string $milestoneTitle, string $contractTitle, string $reason = ''): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        $notification = self::create(
            $userId,
            'payment_failed',
            'Payment Failed',
            "Your payment of {$formatted} for milestone \"{$milestoneTitle}\" could not be processed.",
            '/employer/payments'
        );

        // Send email notification
        self::sendPaymentEmail($userId, 'payment_failed', [
            'reference'      => $reference,
            'amount'         => $amount,
            'milestoneTitle' => $milestoneTitle,
            'contractTitle'  => $contractTitle,
            'reason'         => $reason,
        ]);

        return $notification;
    }

    // ═══════════════════════════════════════════════════════════════════
    // WITHDRAWAL notifications
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Notify freelancer that their withdrawal request was received.
     */
    public static function withdrawalRequested(int $userId, string $reference, float $amount, float $fee, float $netAmount, ?string $methodName = null): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        $notification = self::create(
            $userId,
            'withdrawal_requested',
            'Withdrawal Requested',
            "Your withdrawal request for {$formatted} has been submitted and is being processed.",
            '/freelancer/withdrawals'
        );

        // Send email notification
        self::sendPaymentEmail($userId, 'withdrawal_requested', [
            'reference'  => $reference,
            'amount'     => $amount,
            'fee'        => $fee,
            'netAmount'  => $netAmount,
            'methodName' => $methodName,
        ]);

        return $notification;
    }

    /**
     * Notify freelancer that their withdrawal was completed.
     */
    public static function withdrawalCompleted(int $userId, string $reference, float $amount, float $fee, float $netAmount, ?string $methodName = null): Notification
    {
        $formatted = 'ETB ' . number_format($netAmount, 2);
        $notification = self::create(
            $userId,
            'withdrawal_completed',
            'Withdrawal Completed',
            "Your withdrawal of {$formatted} has been processed and sent to your account.",
            '/freelancer/withdrawals'
        );

        // Send email notification
        self::sendPaymentEmail($userId, 'withdrawal_completed', [
            'reference'  => $reference,
            'amount'     => $amount,
            'fee'        => $fee,
            'netAmount'  => $netAmount,
            'methodName' => $methodName,
        ]);

        return $notification;
    }

    /**
     * Notify freelancer that their withdrawal failed.
     */
    public static function withdrawalFailed(int $userId, string $reference, float $amount, string $reason): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        $notification = self::create(
            $userId,
            'withdrawal_failed',
            'Withdrawal Failed',
            "Your withdrawal of {$formatted} could not be processed. The amount has been returned to your balance.",
            '/freelancer/withdrawals'
        );

        // Send email notification
        self::sendPaymentEmail($userId, 'withdrawal_failed', [
            'reference' => $reference,
            'amount'    => $amount,
            'reason'    => $reason,
        ]);

        return $notification;
    }

    // ═══════════════════════════════════════════════════════════════════
    // MILESTONE LIFECYCLE notifications
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Notify freelancer that a new milestone has been created.
     */
    public static function milestoneCreated(int $freelancerId, string $milestoneTitle, float $amount, string $contractTitle, int $contractId): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        return self::create(
            $freelancerId,
            'milestone_created',
            'New Milestone Created',
            "A new milestone \"{$milestoneTitle}\" ({$formatted}) has been created in contract \"{$contractTitle}\".",
            "/freelancer/contracts/{$contractId}"
        );
    }

    /**
     * Notify freelancer that a milestone has been funded and they can start work.
     */
    public static function milestoneFunded(int $freelancerId, string $milestoneTitle, float $amount, string $contractTitle, int $contractId): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        return self::create(
            $freelancerId,
            'milestone_funded',
            'Milestone Funded — Start Working!',
            "Milestone \"{$milestoneTitle}\" ({$formatted}) in contract \"{$contractTitle}\" has been funded. You can now start working!",
            "/freelancer/contracts/{$contractId}"
        );
    }

    /**
     * Notify employer that the freelancer has started working on a milestone.
     */
    public static function milestoneStarted(int $employerId, string $milestoneTitle, string $contractTitle, int $contractId): Notification
    {
        return self::create(
            $employerId,
            'milestone_started',
            'Work Started',
            "The freelancer has started working on milestone \"{$milestoneTitle}\" in contract \"{$contractTitle}\".",
            "/employer/contracts/{$contractId}"
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // EMAIL DISPATCH
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Dispatch an email notification for payment events.
     *
     * Uses Laravel's queue system so emails are sent asynchronously.
     * Falls back to synchronous sending if queue is not configured.
     */
    private static function sendPaymentEmail(int $userId, string $type, array $data): void
    {
        $user = User::find($userId);
        if (!$user || empty($user->email)) {
            return;
        }

        // Don't send emails in testing environment
        if (app()->environment('testing')) {
            return;
        }

        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
        $currency    = $data['currency'] ?? 'ETB';
        $amount      = $data['amount'] ?? 0;
        $date        = now()->format('F j, Y \a\t g:i A');

        try {
            $mail = match ($type) {
                'escrow_funded' => new PaymentEscrowFundedMail(
                    userName:       $user->name,
                    amount:         $amount,
                    fee:            $data['fee'] ?? round($amount * config('payment.platform_fee_rate', 0.05), 2),
                    currency:       $currency,
                    reference:      $data['reference'] ?? '',
                    milestoneTitle: $data['milestoneTitle'] ?? '',
                    contractTitle:  $data['contractTitle'] ?? '',
                    date:           $date,
                    dashboardUrl:   "{$frontendUrl}/employer/contracts/" . ($data['contractId'] ?? ''),
                ),

                'milestone_paid' => new MilestonePaidMail(
                    userName:       $user->name,
                    amount:         $amount,
                    fee:            $data['fee'] ?? round($amount * config('payment.platform_fee_rate', 0.05), 2),
                    netAmount:      $data['netAmount'] ?? $amount,
                    currency:       $currency,
                    reference:      $data['reference'] ?? '',
                    milestoneTitle: $data['milestoneTitle'] ?? '',
                    contractTitle:  $data['contractTitle'] ?? '',
                    date:           $date,
                    dashboardUrl:   "{$frontendUrl}/freelancer/contracts/" . ($data['contractId'] ?? ''),
                ),

                'withdrawal_requested' => new WithdrawalRequestedMail(
                    userName:     $user->name,
                    amount:       $amount,
                    fee:          $data['fee'] ?? 0,
                    netAmount:    $data['netAmount'] ?? $amount,
                    currency:     $currency,
                    reference:    $data['reference'] ?? '',
                    methodName:   $data['methodName'] ?? null,
                    date:         $date,
                    dashboardUrl: "{$frontendUrl}/freelancer/withdrawals",
                ),

                'withdrawal_completed' => new WithdrawalCompletedMail(
                    userName:     $user->name,
                    amount:       $amount,
                    fee:          $data['fee'] ?? 0,
                    netAmount:    $data['netAmount'] ?? $amount,
                    currency:     $currency,
                    reference:    $data['reference'] ?? '',
                    methodName:   $data['methodName'] ?? null,
                    date:         $date,
                    dashboardUrl: "{$frontendUrl}/freelancer/withdrawals",
                ),

                'withdrawal_failed' => new WithdrawalFailedMail(
                    userName:     $user->name,
                    amount:       $amount,
                    currency:     $currency,
                    reference:    $data['reference'] ?? '',
                    reason:       $data['reason'] ?? '',
                    date:         $date,
                    dashboardUrl: "{$frontendUrl}/freelancer/withdrawals",
                ),

                'refund_completed' => new RefundCompletedMail(
                    userName:          $user->name,
                    amount:            $amount,
                    currency:          $currency,
                    originalReference: $data['reference'] ?? '',
                    reason:            $data['reason'] ?? null,
                    date:              $date,
                    dashboardUrl:      "{$frontendUrl}/freelancer/payments",
                ),

                'payment_failed' => new PaymentFailedMail(
                    userName:       $user->name,
                    amount:         $amount,
                    currency:       $currency,
                    reference:      $data['reference'] ?? '',
                    milestoneTitle: $data['milestoneTitle'] ?? '',
                    contractTitle:  $data['contractTitle'] ?? '',
                    reason:         $data['reason'] ?? null,
                    date:           $date,
                    retryUrl:       "{$frontendUrl}/employer/contracts/" . ($data['contractId'] ?? ''),
                ),

                default => null,
            };

            if ($mail) {
                Mail::to($user->email)->queue($mail);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to send payment email', [
                'user_id' => $userId,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
