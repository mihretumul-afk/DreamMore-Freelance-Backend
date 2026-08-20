<?php

namespace Tests\Feature\Api\V1;

use App\Models\Contract;
use App\Models\Job;
use App\Models\Message;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    private function createContractParticipants(): array
    {
        $employer = User::create([
            'name' => 'Test Employer',
            'email' => 'employer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'employer',
        ]);

        $freelancer = User::create([
            'name' => 'Test Freelancer',
            'email' => 'freelancer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'freelancer',
        ]);

        $job = Job::create([
            'employer_id' => $employer->id,
            'title' => 'Test Job',
            'slug' => 'test-job-' . uniqid(),
            'description' => 'A test job',
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

    public function test_authorized_participants_can_message(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();
        $token = $employer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/messages', [
                'receiver_id' => $freelancer->id,
                'content' => 'Hello, I want to discuss the project.',
                'contract_id' => $contract->id,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Message sent successfully.',
            ]);

        $this->assertDatabaseHas('messages', [
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Hello, I want to discuss the project.',
        ]);
    }

    public function test_unauthorized_user_cannot_message(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer] = $this->createContractParticipants();

        $stranger = User::create([
            'name' => 'Stranger',
            'email' => 'stranger_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'freelancer',
        ]);

        $token = $stranger->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/messages', [
                'receiver_id' => $freelancer->id,
                'content' => 'Spam message',
            ]);

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_message(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'receiver_id' => 1,
            'content' => 'Hello',
        ]);

        $response->assertUnauthorized();
    }

    public function test_messages_list_correctly(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();
        $token = $freelancer->createToken('test')->plainTextToken;

        // Create some messages
        Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Message 1',
        ]);

        Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $freelancer->id,
            'receiver_id' => $employer->id,
            'content' => 'Message 2',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/messages/{$employer->id}");

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_mark_read_works(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();
        $token = $freelancer->createToken('test')->plainTextToken;

        $message = Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Read this',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/messages/{$message->id}/read");

        $response->assertOk();
        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_mark_all_read_works(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();
        $token = $freelancer->createToken('test')->plainTextToken;

        Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Message 1',
        ]);

        Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Message 2',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/messages/{$employer->id}/read-all");

        $response->assertOk();

        $unreadCount = Message::where('receiver_id', $freelancer->id)->whereNull('read_at')->count();
        $this->assertEquals(0, $unreadCount);
    }

    public function test_conversations_endpoint_works(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();
        $token = $employer->createToken('test')->plainTextToken;

        Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $freelancer->id,
            'receiver_id' => $employer->id,
            'content' => 'Hello from freelancer',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/messages/conversations');

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_unread_count_works(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createContractParticipants();
        $token = $freelancer->createToken('test')->plainTextToken;

        Message::create([
            'contract_id' => $contract->id,
            'sender_id' => $employer->id,
            'receiver_id' => $freelancer->id,
            'content' => 'Unread message',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/messages/unread');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['unread_count' => 1],
            ]);
    }
}
