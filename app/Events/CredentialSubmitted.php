<?php

namespace App\Events;

use App\Models\Credential;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CredentialSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The credential that was submitted.
     */
    public Credential $credential;

    /**
     * The freelancer who submitted the credential.
     */
    public string $freelancerName;

    /**
     * Create a new event instance.
     */
    public function __construct(Credential $credential, string $freelancerName)
    {
        $this->credential = $credential;
        $this->freelancerName = $freelancerName;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.credentials'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'credential.submitted';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'credential_id' => $this->credential->id,
            'title' => $this->credential->title,
            'type' => $this->credential->type,
            'freelancer_name' => $this->freelancerName,
            'freelancer_id' => $this->credential->user_id,
            'timestamp' => now()->toISOString(),
        ];
    }
}
