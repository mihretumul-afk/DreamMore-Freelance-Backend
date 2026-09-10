<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Http\Middleware\TrackLastSeen;
use App\Mail\DisputeRaisedEmail;
use App\Mail\EscrowFundedEmail;
use App\Mail\MilestoneFundedEmail;
use App\Mail\MilestonePaidEmail;
use App\Mail\MilestoneSubmittedEmail;
use App\Mail\NewProposalEmail;
use App\Mail\NewMessageEmail;
use App\Mail\ProposalAcceptedEmail;
use App\Mail\WithdrawalCompletedEmail;
use App\Mail\WithdrawalFailedEmail;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationService
{
    /**
     * Send a queued email to a user. Purely additive: any mail failure is
     * logged and swallowed so in-app notifications keep working exactly as
     * before. Emails are queued on the database connection, so a slow or
     * down mail provider never blocks a user-facing request.
     */
    protected static function sendEmail(int $userId, object $mailable): void
    {
        try {
            $user = User::find($userId);

            if (! $user || ! $user->email) {
                return;
            }

            Mail::to($user->email)->queue($mailable);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to queue email notification', [
                'user_id' => $userId,
                'mailable' => $mailable::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check whether the user is currently online (active in the last 5
     * minutes, tracked by TrackLastSeen middleware).
     */
    public static function isUserOnline(int $userId): bool
    {
        try {
            return Cache::has(TrackLastSeen::ONLINE_KEY.":{$userId}");
        } catch (Throwable $e) {
            return false;
        }
    }

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
        try {
            broadcast(new NotificationCreated($notification));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to broadcast notification', [
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return $notification;
    }

    /**
     * Notify employer about a new proposal.
     */
    public static function newProposal(int $employerId, string $freelancerName, string $jobTitle, int $jobId): Notification
    {
        $notification = self::create(
            $employerId,
            'new_proposal',
            'New Proposal Received',
            "{$freelancerName} submitted a proposal for '{$jobTitle}'.",
            "/employer/jobs/{$jobId}/proposals"
        );

        self::sendEmail($employerId, new NewProposalEmail(
            User::find($employerId)?->name ?? 'there',
            $freelancerName,
            $jobTitle,
            $jobId,
        ));

        return $notification;
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

        $notification = self::create(
            $freelancerId,
            'proposal_' . $status,
            'Proposal ' . ucfirst($status),
            $messages[$status] ?? "Your proposal status for '{$jobTitle}' has changed to {$status}.",
            $links[$status] ?? "/freelancer/proposals"
        );

        // Email only on acceptance — the contract offer is the important,
        // actionable event. Shortlist/rejection stay in-app only.
        if ($status === 'accepted') {
            self::sendEmail($freelancerId, new ProposalAcceptedEmail(
                User::find($freelancerId)?->name ?? 'there',
                $jobTitle,
            ));
        }

        return $notification;
    }

    /**
     * Notify about contract creation.
     */
    public static function contractCreated(int $userId, string $title, string $role, ?int $contractId = null): Notification
    {
        $link = $role === 'employer' ? "/employer/contracts" : "/freelancer/contracts";
        if ($contractId) {
            $link = $role === 'employer' ? "/employer/contracts/{$contractId}" : "/freelancer/contracts/{$contractId}";
        }
        $message = $role === 'employer'
            ? "Contract offer created for '{$title}' and sent to freelancer."
            : "Your proposal for '{$title}' was accepted. A contract offer has been created for your review.";

        return self::create(
            $userId,
            'contract_created',
            'Contract Offer Created',
            $message,
            $link
        );
    }

    /**
     * Notify about contract accepted.
     */
    public static function contractAccepted(int $userId, string $title, string $role, int $contractId): Notification
    {
        $link = $role === 'employer' ? "/employer/contracts/{$contractId}" : "/freelancer/contracts/{$contractId}";
        $message = $role === 'employer'
            ? "The freelancer has accepted the contract offer for '{$title}'. The contract is now Active."
            : "You have accepted the contract for '{$title}'. The contract is now Active.";

        return self::create(
            $userId,
            'contract_accepted',
            'Contract Offer Accepted',
            $message,
            $link
        );
    }

    /**
     * Notify about contract declined.
     */
    public static function contractDeclined(int $userId, string $title, string $role, int $contractId, ?string $reason = null): Notification
    {
        $link = $role === 'employer' ? "/employer/contracts/{$contractId}" : "/freelancer/contracts/{$contractId}";
        $message = $role === 'employer'
            ? "The freelancer declined the contract offer for '{$title}'."
            : "You declined the contract offer for '{$title}'.";
        if ($reason) {
            $message .= " Reason: {$reason}";
        }

        return self::create(
            $userId,
            'contract_declined',
            'Contract Offer Declined',
            $message,
            $link
        );
    }

    /**
     * Notify that a milestone has been funded.
     */
    public static function milestoneFunded(int $freelancerId, string $milestoneTitle, string $contractTitle, int $contractId, float $amount): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        $notification = self::create(
            $freelancerId,
            'milestone_funded',
            'Milestone Funded',
            "Milestone \"{$milestoneTitle}\" ({$formatted}) in contract \"{$contractTitle}\" has been funded. You can now start working!",
            "/freelancer/contracts/{$contractId}"
        );

        self::sendEmail($freelancerId, new MilestoneFundedEmail(
            User::find($freelancerId)?->name ?? 'there',
            $milestoneTitle,
            $contractTitle,
            $contractId,
            $amount,
        ));

        return $notification;
    }

    /**
     * Notify freelancer that payment has been released.
     */
    public static function milestonePaid(
        int $freelancerId,
        string $milestoneTitle,
        int $contractId,
        float $amount,
        float $platformFee = 0.0,
        ?string $reference = null,
        ?string $contractTitle = null,
    ): Notification {
        $formatted = 'ETB ' . number_format($amount, 2);
        $notification = self::create(
            $freelancerId,
            'milestone_paid',
            'Payment Released',
            "You received {$formatted} for completing milestone \"{$milestoneTitle}\". The funds are now in your pending balance.",
            "/freelancer/contracts/{$contractId}"
        );

        self::sendEmail($freelancerId, new MilestonePaidEmail(
            User::find($freelancerId)?->name ?? 'there',
            $reference ?? 'Milestone-'.$contractId,
            $milestoneTitle,
            $contractTitle ?? "Contract #{$contractId}",
            $contractId,
            $amount + $platformFee,
            $platformFee,
            $amount,
        ));

        return $notification;
    }

    /**
     * Email the EMPLOYER that their escrow payment for a milestone was
     * confirmed. Email-only: no in-app notification is created for this
     * today, and adding one would change existing behavior — this wiring
     * is strictly additive.
     */
    public static function employerEscrowFunded(
        int $employerId,
        string $reference,
        string $milestoneTitle,
        string $contractTitle,
        int $contractId,
        float $amount,
        float $fee = 0.0,
    ): void {
        self::sendEmail($employerId, new EscrowFundedEmail(
            User::find($employerId)?->name ?? 'there',
            $reference,
            $milestoneTitle,
            $contractTitle,
            $contractId,
            $amount,
            $fee,
        ));
    }

    /**
     * Notify about withdrawal requested.
     */
    public static function withdrawalRequested(int $userId, string $reference, float $amount, float $fee, float $netAmount): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        return self::create(
            $userId,
            'withdrawal_requested',
            'Withdrawal Requested',
            "Your withdrawal request for {$formatted} has been submitted and is being processed.",
            '/freelancer/earnings'
        );
    }

    /**
     * Notify about withdrawal completed.
     */
    public static function withdrawalCompleted(int $userId, string $reference, float $amount, float $fee, float $netAmount): Notification
    {
        $formatted = 'ETB ' . number_format($netAmount, 2);
        $notification = self::create(
            $userId,
            'withdrawal_completed',
            'Withdrawal Completed',
            "Your withdrawal of {$formatted} has been processed and sent to your account.",
            '/freelancer/earnings'
        );

        self::sendEmail($userId, new WithdrawalCompletedEmail(
            User::find($userId)?->name ?? 'there',
            $reference,
            $amount,
            $fee,
            $netAmount,
        ));

        return $notification;
    }

    /**
     * Notify about withdrawal failed.
     */
    public static function withdrawalFailed(int $userId, string $reference, float $amount, string $reason): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        $notification = self::create(
            $userId,
            'withdrawal_failed',
            'Withdrawal Failed',
            "Your withdrawal of {$formatted} could not be processed. The amount has been returned to your balance.",
            '/freelancer/earnings'
        );

        self::sendEmail($userId, new WithdrawalFailedEmail(
            User::find($userId)?->name ?? 'there',
            $reference,
            $amount,
            $reason,
        ));

        return $notification;
    }

    /**
     * Notify about payment refund.
     */
    public static function paymentRefunded(int $userId, string $reference, float $amount, string $currency = 'ETB'): Notification
    {
        $formatted = "{$currency} " . number_format($amount, 2);
        return self::create(
            $userId,
            'payment_refunded',
            'Refund Issued',
            "A refund of {$formatted} has been issued for payment {$reference}.",
            '/employer/contracts'
        );
    }

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
     * Notify employer that freelancer started working on a milestone.
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
    public static function messageReceived(int $receiverId, string $senderName, ?string $messagePreview = null): Notification
    {
        $notification = self::create(
            $receiverId,
            'message_received',
            'New Message',
            "You have a new message from {$senderName}.",
            '/freelancer/messages'
        );

        // Email only when the recipient is offline. Active users see the
        // in-app + realtime notification immediately, so an email would be
        // spam. Queued with a 60s delay so a run of quick messages while
        // the user is still offline produces at most one email.
        if (! self::isUserOnline($receiverId)) {
            self::sendEmail($receiverId, new NewMessageEmail(
                User::find($receiverId)?->name ?? 'there',
                $senderName,
                $messagePreview ? str($messagePreview)->limit(120) : 'Open Dream More to read the message.',
            ));
        }

        return $notification;
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
     * Query active admins whose active role grants at least one of the
     * given permissions. Super Admins are always included.
     *
     * @param string[] $permsList
     */
    protected static function adminsWithPermissions(array $permsList): \Illuminate\Support\Collection
    {
        return User::where('role', 'admin')
            ->where('status', 'active')
            ->where(function ($query) use ($permsList) {
                $query->whereHas('adminRoles', function ($roleQuery) use ($permsList) {
                    $roleQuery->where('is_active', true)
                        ->where(function ($roleInner) use ($permsList) {
                            $roleInner->where('slug', Role::SUPER_ADMIN)
                                ->orWhereHas('permissions', fn ($permissionQuery) => $permissionQuery->whereIn('slug', $permsList));
                        });
                });
            })
            ->get();
    }

    /**
     * Notify administrators of an administrative event, routed by role.
     *
     * Only active admins whose active role grants at least one of the given permissions
     * receive the notification. Super Admins always receive it.
     *
     * Common routing examples:
     *   - 'users.verify'    → support admins (credential/verification review)
     *   - ['finance.view', 'withdrawals.view'] → finance admins (withdrawals, payments)
     *   - 'disputes.review' → dispute admins (dispute updates)
     *   - 'contacts.manage' → contact admins (inquiries)
     */
    public static function notifyAdmins(string|array $permissions, string $type, string $title, string $message, ?string $link = null): void
    {
        $permsList = array_values((array) $permissions);

        $admins = self::adminsWithPermissions($permsList);

        foreach ($admins as $admin) {
            self::create($admin->id, $type, $title, $message, $link);
        }
    }

    /**
     * Email a contract party (employer or freelancer) about a raised dispute.
     */
    public static function disputeEmail(
        int $userId,
        string $contractTitle,
        int $contractId,
        string $milestoneTitle,
        string $reason,
        string $role,
    ): void {
        self::sendEmail($userId, new DisputeRaisedEmail(
            User::find($userId)?->name ?? 'there',
            $contractTitle,
            $contractId,
            $milestoneTitle,
            $reason,
            $role,
        ));
    }

    /**
     * Email admins with dispute-review permission about a new dispute.
     */
    public static function disputeAdminEmail(
        string $contractTitle,
        int $contractId,
        string $milestoneTitle,
        string $reason,
    ): void {
        $admins = self::adminsWithPermissions(['disputes.review']);

        foreach ($admins as $admin) {
            self::sendEmail($admin->id, new DisputeRaisedEmail(
                $admin->name,
                $contractTitle,
                $contractId,
                $milestoneTitle,
                $reason,
                'admin',
            ));
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
     * Notify participants about admin note on a dispute.
     */
    public static function disputeUpdate(int $userId, string $disputeReason, int $reportId, string $note, string $role = 'freelancer'): Notification
    {
        $link = match ($role) {
            'employer' => "/employer/disputes/{$reportId}",
            'admin'    => "/admin/reports/{$reportId}",
            default    => "/freelancer/disputes/{$reportId}",
        };

        return self::create(
            $userId,
            'dispute_update',
            'Dispute Update',
            "Dispute update ({$disputeReason}): {$note}",
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
            'users.verify',
            'new_freelancer_registration',
            'New Freelancer Registration',
            "{$freelancerName} has registered as a freelancer and is awaiting approval.",
            '/admin/freelancers?status=pending'
        );
    }

    /**
     * Notify user that wallet deposit was confirmed.
     */
    public static function walletDepositCompleted(int $userId, string $reference, float $amount, float $newBalance): Notification
    {
        $formatted = 'ETB ' . number_format($amount, 2);
        $balanceFormatted = 'ETB ' . number_format($newBalance, 2);
        $role = \App\Models\User::find($userId)?->role ?? 'freelancer';
        $link = $role === 'employer' ? '/employer/settings/payment-methods' : '/freelancer/withdrawals';

        return self::create(
            $userId,
            'wallet_deposit_completed',
            'Funds Added Successfully',
            "{$formatted} has been added to your wallet. New balance: {$balanceFormatted}.",
            $link
        );
    }
}
