<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;

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
}
