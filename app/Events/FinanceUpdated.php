<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FinanceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The type of financial update.
     */
    public string $type;

    /**
     * The update data.
     */
    public array $data;

    /**
     * User ID who performed the action.
     */
    public ?int $actorId;

    /**
     * Create a new event instance.
     *
     * @param string $type The type: payment, withdrawal, refund, milestone_funded, milestone_released
     * @param array $data The financial data
     * @param int|null $actorId The user who performed the action
     */
    public function __construct(string $type, array $data = [], ?int $actorId = null)
    {
        $this->type = $type;
        $this->data = $data;
        $this->actorId = $actorId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('admin.finance'),
        ];

        // Also broadcast to specific user channels
        if (isset($this->data['employer_id'])) {
            $channels[] = new PrivateChannel('employer.' . $this->data['employer_id']);
        }
        if (isset($this->data['freelancer_id'])) {
            $channels[] = new PrivateChannel('freelancer.' . $this->data['freelancer_id']);
        }
        if (isset($this->data['user_id'])) {
            $channels[] = new PrivateChannel('freelancer.' . $this->data['user_id']);
            $channels[] = new PrivateChannel('employer.' . $this->data['user_id']);
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'finance.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type'      => $this->type,
            'data'      => $this->data,
            'actor_id'  => $this->actorId,
            'timestamp' => now()->toISOString(),
        ];
    }
}
