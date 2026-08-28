<?php

namespace App\Events;

use App\Models\Milestone;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MilestoneUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The milestone instance.
     */
    public Milestone $milestone;

    /**
     * The type of update (status_change, funded, submitted, etc.).
     */
    public string $updateType;

    /**
     * Additional data about the update.
     */
    public array $updateData;

    /**
     * Create a new event instance.
     */
    public function __construct(Milestone $milestone, string $updateType = 'status_change', array $updateData = [])
    {
        $this->milestone = $milestone;
        $this->updateType = $updateType;
        $this->updateData = $updateData;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $contract = $this->milestone->contract;

        return [
            new PrivateChannel('contract.' . $this->milestone->contract_id),
            new PrivateChannel('contract.' . $contract->employer_id),
            new PrivateChannel('contract.' . $contract->freelancer_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'milestone.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->milestone->id,
            'contract_id' => $this->milestone->contract_id,
            'title' => $this->milestone->title,
            'amount' => (float) $this->milestone->amount,
            'status' => $this->milestone->status,
            'due_date' => $this->milestone->due_date?->toISOString(),
            'escrow_funded_at' => $this->milestone->escrow_funded_at?->toISOString(),
            'submitted_at' => $this->milestone->submitted_at?->toISOString(),
            'approved_at' => $this->milestone->approved_at?->toISOString(),
            'paid_at' => $this->milestone->paid_at?->toISOString(),
            'update_type' => $this->updateType,
            'update_data' => $this->updateData,
            'updated_at' => $this->milestone->updated_at->toISOString(),
        ];
    }
}
