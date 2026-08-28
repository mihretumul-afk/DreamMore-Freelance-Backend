<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\Milestone;
use App\Models\User;

/**
 * Service for logging contract activities.
 * These activities are shown in the Activity Log tab on contract details pages.
 */
class ContractActivityService
{
    /**
     * Log a contract activity.
     */
    public static function log(
        Contract $contract,
        string $action,
        ?int $actorId = null,
        ?int $milestoneId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $description = null,
        array $metadata = [],
    ): ContractActivity {
        return ContractActivity::create([
            'contract_id' => $contract->id,
            'milestone_id' => $milestoneId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    // ── Contract Actions ──────────────────────────────────────────────

    public static function contractStarted(Contract $contract, int $actorId): ContractActivity
    {
        return self::log(
            $contract,
            ContractActivity::ACTION_CONTRACT_STARTED,
            $actorId,
            null,
            'Contract',
            $contract->id,
            'Contract started between ' . $contract->employer->name . ' and ' . $contract->freelancer->name,
        );
    }

    public static function contractPaused(Contract $contract, int $actorId): ContractActivity
    {
        return self::log(
            $contract,
            ContractActivity::ACTION_CONTRACT_PAUSED,
            $actorId,
            null,
            'Contract',
            $contract->id,
            $contract->employer->name . ' paused the contract',
        );
    }

    public static function contractResumed(Contract $contract, int $actorId): ContractActivity
    {
        return self::log(
            $contract,
            ContractActivity::ACTION_CONTRACT_RESUMED,
            $actorId,
            null,
            'Contract',
            $contract->id,
            $contract->employer->name . ' resumed the contract',
        );
    }

    public static function contractCompleted(Contract $contract, int $actorId): ContractActivity
    {
        return self::log(
            $contract,
            ContractActivity::ACTION_CONTRACT_COMPLETED,
            $actorId,
            null,
            'Contract',
            $contract->id,
            'Contract completed successfully',
        );
    }

    public static function contractCancelled(Contract $contract, int $actorId): ContractActivity
    {
        return self::log(
            $contract,
            ContractActivity::ACTION_CONTRACT_CANCELLED,
            $actorId,
            null,
            'Contract',
            $contract->id,
            $contract->employer->name . ' cancelled the contract',
        );
    }

    // ── Milestone Actions ─────────────────────────────────────────────

    public static function milestoneCreated(Milestone $milestone, int $actorId): ContractActivity
    {
        $contract = $milestone->contract;
        return self::log(
            $contract,
            ContractActivity::ACTION_MILESTONE_CREATED,
            $actorId,
            $milestone->id,
            'Milestone',
            $milestone->id,
            $contract->employer->name . ' created milestone "' . $milestone->title . '" (ETB ' . number_format($milestone->amount, 2) . ')',
            ['milestone_title' => $milestone->title, 'amount' => $milestone->amount],
        );
    }

    public static function milestoneUpdated(Milestone $milestone, int $actorId): ContractActivity
    {
        return self::log(
            $milestone->contract,
            ContractActivity::ACTION_MILESTONE_UPDATED,
            $actorId,
            $milestone->id,
            'Milestone',
            $milestone->id,
            'Milestone "' . $milestone->title . '" was updated',
            ['milestone_title' => $milestone->title],
        );
    }

    public static function milestoneFunded(Milestone $milestone, int $actorId): ContractActivity
    {
        $contract = $milestone->contract;
        return self::log(
            $contract,
            ContractActivity::ACTION_MILESTONE_FUNDED,
            $actorId,
            $milestone->id,
            'Milestone',
            $milestone->id,
            $contract->employer->name . ' funded milestone "' . $milestone->title . '" with ETB ' . number_format($milestone->amount, 2),
            ['milestone_title' => $milestone->title, 'amount' => $milestone->amount],
        );
    }

    public static function milestoneStarted(Milestone $milestone, int $actorId): ContractActivity
    {
        $contract = $milestone->contract;
        return self::log(
            $contract,
            ContractActivity::ACTION_MILESTONE_STARTED,
            $actorId,
            $milestone->id,
            'Milestone',
            $milestone->id,
            $contract->freelancer->name . ' started working on milestone "' . $milestone->title . '"',
            ['milestone_title' => $milestone->title],
        );
    }

    public static function milestoneSubmitted(Milestone $milestone, int $actorId): ContractActivity
    {
        $contract = $milestone->contract;
        return self::log(
            $contract,
            ContractActivity::ACTION_MILESTONE_SUBMITTED,
            $actorId,
            $milestone->id,
            'Milestone',
            $milestone->id,
            $contract->freelancer->name . ' submitted work for milestone "' . $milestone->title . '"',
            ['milestone_title' => $milestone->title],
        );
    }

    public static function milestoneApproved(Milestone $milestone, int $actorId): ContractActivity
    {
        $contract = $milestone->contract;
        return self::log(
            $contract,
            ContractActivity::ACTION_MILESTONE_APPROVED,
            $actorId,
            $milestone->id,
            'Milestone',
            $milestone->id,
            $contract->employer->name . ' approved milestone "' . $milestone->title . '"',
            ['milestone_title' => $milestone->title, 'amount' => $milestone->amount],
        );
    }

    public static function milestoneReleased(Milestone $milestone, int $actorId, float $netAmount): ContractActivity
    {
        $contract = $milestone->contract;
        return self::log(
            $contract,
            ContractActivity::ACTION_MILESTONE_RELEASED,
            $actorId,
            $milestone->id,
            'Milestone',
            $milestone->id,
            'Payment of ETB ' . number_format($netAmount, 2) . ' released to ' . $contract->freelancer->name . ' for milestone "' . $milestone->title . '"',
            ['milestone_title' => $milestone->title, 'net_amount' => $netAmount],
        );
    }

    public static function milestoneRevisionRequested(Milestone $milestone, int $actorId, ?string $note): ContractActivity
    {
        $contract = $milestone->contract;
        $description = $contract->employer->name . ' requested revision for milestone "' . $milestone->title . '"';
        if ($note) {
            $description .= ': ' . $note;
        }

        return self::log(
            $contract,
            ContractActivity::ACTION_MILESTONE_REVISION_REQUESTED,
            $actorId,
            $milestone->id,
            'Milestone',
            $milestone->id,
            $description,
            ['milestone_title' => $milestone->title, 'revision_note' => $note],
        );
    }

    // ── Helper Methods ────────────────────────────────────────────────

    /**
     * Get activity feed for a contract.
     */
    public static function getActivities(Contract $contract, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return ContractActivity::where('contract_id', $contract->id)
            ->with(['actor:id,name,role', 'milestone:id,title'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
