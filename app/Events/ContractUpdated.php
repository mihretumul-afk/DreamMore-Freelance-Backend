<?php

namespace App\Events;

use App\Models\Contract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The contract instance.
     */
    public Contract $contract;

    /**
     * The type of update (status_change, milestone_added, etc.).
     */
    public string $updateType;

    /**
     * Additional data about the update.
     */
    public array $updateData;

    /**
     * Create a new event instance.
     */
    public function __construct(Contract $contract, string $updateType = 'status_change', array $updateData = [])
    {
        $this->contract = $contract;
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
        return [
            new PrivateChannel('contract.' . $this->contract->id),
            new PrivateChannel('contract.' . $this->contract->employer_id),
            new PrivateChannel('contract.' . $this->contract->freelancer_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'contract.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->contract->id,
            'title' => $this->contract->title,
            'status' => $this->contract->status,
            'employer_id' => $this->contract->employer_id,
            'freelancer_id' => $this->contract->freelancer_id,
            'total_amount' => (float) $this->contract->total_amount,
            'update_type' => $this->updateType,
            'update_data' => $this->updateData,
            'updated_at' => $this->contract->updated_at->toISOString(),
        ];
    }
}
