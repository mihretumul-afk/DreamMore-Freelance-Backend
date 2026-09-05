<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use App\Notifications\ResetPasswordNotification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_email_for_existing_user(): void
    {
        Notification::fake();

        $user = User::create([
            'name'     => 'Test User',
            'email'    => 'testuser@example.com',
            'password' => Hash::make('oldpassword123'),
            'role'     => 'freelancer',
        ]);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'testuser@example.com',
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Password reset link sent. Please check your email.',
                 ]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_forgot_password_returns_generic_success_for_non_existent_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Password reset link sent. Please check your email.',
                 ]);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_validation_fails_on_invalid_email(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::create([
            'name'     => 'Reset User',
            'email'    => 'reset@example.com',
            'password' => Hash::make('oldpassword123'),
            'role'     => 'employer',
        ]);

        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'reset@example.com',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Password reset successfully.',
                 ]);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
        $this->assertFalse(Hash::check('oldpassword123', $user->fresh()->password));
    }

    public function test_reset_password_fails_with_invalid_token(): void
    {
        $user = User::create([
            'name'     => 'Reset User',
            'email'    => 'reset@example.com',
            'password' => Hash::make('oldpassword123'),
            'role'     => 'employer',
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => 'invalid-token-12345',
            'email'                 => 'reset@example.com',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('oldpassword123', $user->fresh()->password));
    }

    public function test_reset_password_fails_when_passwords_do_not_match(): void
    {
        $user = User::create([
            'name'     => 'Reset User',
            'email'    => 'reset@example.com',
            'password' => Hash::make('oldpassword123'),
            'role'     => 'employer',
        ]);

        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'reset@example.com',
            'password'              => 'newpassword123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_fails_when_password_too_short(): void
    {
        $user = User::create([
            'name'     => 'Reset User',
            'email'    => 'reset@example.com',
            'password' => Hash::make('oldpassword123'),
            'role'     => 'employer',
        ]);

        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'reset@example.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
    }

    public function test_token_cannot_be_reused(): void
    {
        $user = User::create([
            'name'     => 'Reset User',
            'email'    => 'reset@example.com',
            'password' => Hash::make('oldpassword123'),
            'role'     => 'freelancer',
        ]);

        $token = Password::createToken($user);

        // First reset — successful
        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'reset@example.com',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(200);

        // Second reset with same token — should fail
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'reset@example.com',
            'password'              => 'anotherpassword123',
            'password_confirmation' => 'anotherpassword123',
        ]);

        $response->assertStatus(422);
    }
}
