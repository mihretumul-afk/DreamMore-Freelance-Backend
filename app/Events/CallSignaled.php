<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallSignaled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $senderId;
    public int $receiverId;
    public string $type;
    public ?array $signal;
    public ?string $senderName;
    public ?string $senderAvatar;

    /**
     * Create a new event instance.
     */
    public function __construct(
        int $senderId,
        int $receiverId,
        string $type,
        ?array $signal = null,
        ?string $senderName = null,
        ?string $senderAvatar = null
    ) {
        $this->senderId = $senderId;
        $this->receiverId = $receiverId;
        $this->type = $type;
        $this->signal = $signal;
        $this->senderName = $senderName;
        $this->senderAvatar = $senderAvatar;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->receiverId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'call.signal';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'sender_id' => $this->senderId,
            'receiver_id' => $this->receiverId,
            'type' => $this->type,
            'signal' => $this->signal,
            'sender_name' => $this->senderName,
            'sender_avatar' => $this->senderAvatar,
        ];
    }
}
