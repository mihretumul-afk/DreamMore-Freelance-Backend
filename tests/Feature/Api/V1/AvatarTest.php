<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role = 'freelancer'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => "{$role}_" . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => $role,
        ]);
    }

    public function test_authenticated_user_can_upload_avatar(): void
    {
        Storage::fake('public');
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/avatar', ['avatar' => $file]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Profile photo uploaded successfully.',
            ])
            ->assertJsonStructure(['data' => ['avatar', 'user']]);

        $this->assertNotNull($user->fresh()->avatar);
    }

    public function test_unauthenticated_user_cannot_upload_avatar(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $response = $this->postJson('/api/v1/avatar', ['avatar' => $file]);

        $response->assertUnauthorized();
    }

    public function test_employer_can_upload_avatar(): void
    {
        Storage::fake('public');
        $user = $this->createUser('employer');
        $token = $user->createToken('test')->plainTextToken;

        $file = UploadedFile::fake()->image('avatar.png', 200, 200);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/avatar', ['avatar' => $file]);

        $response->assertOk();
        $this->assertNotNull($user->fresh()->avatar);
    }

    public function test_admin_can_upload_avatar(): void
    {
        Storage::fake('public');
        $user = $this->createUser('admin');
        $token = $user->createToken('test')->plainTextToken;

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/avatar', ['avatar' => $file]);

        $response->assertOk();
        $this->assertNotNull($user->fresh()->avatar);
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $file = UploadedFile::fake()->create('script.php', 100, 'application/php');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/avatar', ['avatar' => $file]);

        $response->assertStatus(422);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        // Create a file larger than 5MB
        $file = UploadedFile::fake()->create('large.jpg', 6000, 'image/jpeg');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/avatar', ['avatar' => $file]);

        $response->assertStatus(422);
    }

    public function test_user_can_remove_avatar(): void
    {
        $user = $this->createUser();
        $user->update(['avatar' => '/storage/avatars/test.jpg']);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/avatar');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Profile photo removed successfully.',
            ]);

        $this->assertNull($user->fresh()->avatar);
    }

    public function test_remove_avatar_when_none_exists_returns_404(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/avatar');

        $response->assertStatus(404);
    }

    public function test_replacing_avatar_updates_url(): void
    {
        Storage::fake('public');
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        // Upload first
        $file1 = UploadedFile::fake()->image('avatar1.jpg', 200, 200);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/avatar', ['avatar' => $file1]);

        $firstAvatar = $user->fresh()->avatar;

        // Replace
        $file2 = UploadedFile::fake()->image('avatar2.png', 200, 200);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/avatar', ['avatar' => $file2]);

        $secondAvatar = $user->fresh()->avatar;

        $this->assertNotNull($secondAvatar);
        $this->assertNotEquals($firstAvatar, $secondAvatar);
    }
}
