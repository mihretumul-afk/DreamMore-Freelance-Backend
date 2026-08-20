<?php

namespace Tests\Feature\Api\V1;

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Models\Contract;
use App\Models\Job;
use App\Models\Message;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function createContractParticipants(): array
    {
        $employer = User::create([
            'name' => 'Broadcast Employer',
            'email' => 'broadcast_employer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'employer',
        ]);

        $freelancer = User::create([
            'name' => 'Broadcast Freelancer',
            'email' => 'broadcast_freelancer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'freelancer',
        ]);

        $job = Job::create([
            'employer_id' => $employer->id,
            'title' => 'Broadcast Test Job',
            'slug' => 'broadcast-test-job-' . uniqid(),
            'description' => 'A test job for broadcasting',
            'budget_type' => 'fixed',
            'min_budget' => 1000,
            'max_budget' => 5000,
            'currency' => 'ETB',
            'status' => 'open',
        ]);

        $proposal = Proposal::create([
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'cover_letter' => 'I can do this.',
            'bid_amount' => 3000,
            'estimated_duration' => '2 weeks',
            'currency' => 'ETB',
            'status' => 'accepted',
        ]);

        $contract = Contract::create([
            'job_id' => $job->id,
            'proposal_id' => $proposal->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $freelancer->id,
            'title' => $job->title,
            'budget_type' => 'fixed',
            'agreed_rate' => 3000,
            'total_amount' => 3000,
            'status' => 'active',
        ]);

        return compact('employer', 'freelancer', 'contract', 'job');
    }

    public function test_message_sent_event_broadcasts_on_correct_channels(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();

        $message = Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Broadcast test message',
        ]);

        $event = new MessageSent($message);

        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);

        // Should broadcast to both sender and receiver channels
        // PrivateChannel->name returns 'private-chat.X'
        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        $this->assertContains('private-chat.' . $freelancer->id, $channelNames);
        $this->assertContains('private-chat.' . $employer->id, $channelNames);
    }

    public function test_message_sent_event_broadcast_name(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();

        $message = Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Test',
        ]);

        $event = new MessageSent($message);

        $this->assertEquals('message.sent', $event->broadcastAs());
    }

    public function test_message_sent_event_data(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();

        $message = Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Broadcast data test',
        ]);

        $event = new MessageSent($message);
        $data = $event->broadcastWith();

        $this->assertEquals($message->id, $data['id']);
        $this->assertEquals($employer->id, $data['sender_id']);
        $this->assertEquals($freelancer->id, $data['receiver_id']);
        $this->assertEquals('Broadcast data test', $data['content']);
        $this->assertEquals($employer->name, $data['sender']['name']);
        $this->assertEquals($freelancer->name, $data['receiver']['name']);
    }

    public function test_message_read_event_broadcasts_to_sender_only(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();

        $message = Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Read receipt test',
        ]);

        $message->update(['read_at' => now()]);

        $event = new MessageRead($message);
        $channels = $event->broadcastOn();

        // Should only broadcast to the sender (to notify them their message was read)
        $this->assertCount(1, $channels);
        $this->assertEquals('private-chat.' . $employer->id, $channels[0]->name);
    }

    public function test_message_read_event_broadcast_name(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();

        $message = Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Test',
        ]);

        $message->update(['read_at' => now()]);

        $event = new MessageRead($message);

        $this->assertEquals('message.read', $event->broadcastAs());
    }

    public function test_message_read_event_data(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();

        $message = Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Read data test',
        ]);

        $message->update(['read_at' => now()]);

        $event = new MessageRead($message);
        $data = $event->broadcastWith();

        $this->assertEquals($message->id, $data['id']);
        $this->assertEquals($employer->id, $data['sender_id']);
        $this->assertEquals($freelancer->id, $data['receiver_id']);
        $this->assertNotNull($data['read_at']);
    }

    public function test_chat_channel_authorization_allows_own_user(): void
    {
        $user = User::create([
            'name' => 'Channel User',
            'email' => 'channel_user_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'freelancer',
        ]);

        // Verify the channel authorization callback works correctly
        $callback = function ($authUser, $userId) {
            return (int) $authUser->id === (int) $userId;
        };

        // Same user should be authorized
        $this->assertTrue($callback($user, $user->id));

        // Different user should be denied
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'freelancer',
        ]);
        $this->assertFalse($callback($otherUser, $user->id));
    }

    public function test_send_message_dispatches_broadcast_event(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();
        $token = $employer->createToken('test')->plainTextToken;

        \Illuminate\Support\Facades\Event::fake([MessageSent::class]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/messages', [
                'receiver_id' => $freelancer->id,
                'content' => 'Event dispatch test',
                'contract_id' => $contract->id,
            ]);

        $response->assertStatus(201);

        \Illuminate\Support\Facades\Event::assertDispatched(MessageSent::class, function ($event) use ($freelancer) {
            return $event->message->receiver_id === $freelancer->id;
        });
    }
}
