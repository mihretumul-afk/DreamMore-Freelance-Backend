<?php

namespace Tests\Feature\Api\V1;

use App\Models\EmployerProfile;
use App\Models\FreelancerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Replace the Socialite driver with one that returns the given fake
     * Google account, so the web callback can be tested end-to-end.
     */
    private function stubGoogleUser(array $data): void
    {
        $googleUser = \Mockery::mock();
        $googleUser->shouldReceive('getId')->andReturn($data['id']);
        $googleUser->shouldReceive('getEmail')->andReturn($data['email']);
        $googleUser->shouldReceive('getName')->andReturn($data['name'] ?? 'Google User');
        $googleUser->shouldReceive('getAvatar')->andReturn($data['avatar'] ?? null);

        $driver = \Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->andReturn($driver);
    }

    /**
     * Follow the Location header of a callback redirect and return its query
     * string, so assertions can check the token/new/error parameters.
     */
    private function callbackQuery($response): array
    {
        $location = $response->headers->get('Location');
        $query = (string) parse_url((string) $location, PHP_URL_QUERY);
        parse_str($query, $params);

        return $params;
    }


    /**
     * Build a fresh Google-created user (as the OAuth callback would).
     */
    private function googleUser(string $email, string $googleId): User
    {
        return User::create([
            'name'      => 'Google User',
            'email'     => $email,
            'password'  => \Illuminate\Support\Str::random(40), // never used for login
            'google_id' => $googleId,
            'role'      => 'freelancer', // placeholder until the role is chosen
            'status'    => 'active',
        ]);
    }

    /**
     * Brand-new Google signups (no profile yet) can finish signup as a freelancer.
     */
    public function test_google_user_can_complete_role_as_freelancer(): void
    {
        $user = $this->googleUser('freelancer@gmail.com', 'google-freelancer-123');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/google/complete-role', ['role' => 'freelancer']);

        $response->assertOk()
            ->assertJsonPath('data.user.role', 'freelancer')
            ->assertJsonPath('data.user.email', 'freelancer@gmail.com');

        $this->assertDatabaseHas('freelancer_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('employer_profiles', ['user_id' => $user->id]);
    }

    /**
     * Brand-new Google signups can finish signup as an employer.
     */
    public function test_google_user_can_complete_role_as_employer(): void
    {
        $user = $this->googleUser('employer@gmail.com', 'google-employer-123');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/google/complete-role', ['role' => 'employer']);

        $response->assertOk()
            ->assertJsonPath('data.user.role', 'employer');

        $this->assertDatabaseHas('employer_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('freelancer_profiles', ['user_id' => $user->id]);
    }

    /**
     * Only accounts created via Google may complete a role.
     */
    public function test_complete_role_rejects_non_google_accounts(): void
    {
        $user = User::create([
            'name'     => 'Password User',
            'email'    => 'user@example.com',
            'password' => bcrypt('password123'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/google/complete-role', ['role' => 'employer'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This account was not created with Google sign-in.');
    }

    /**
     * Google sign-in never grants admin — role=admin is rejected outright.
     */
    public function test_complete_role_cannot_create_an_admin(): void
    {
        $user = $this->googleUser('user@gmail.com', 'google-user-123');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/google/complete-role', ['role' => 'admin'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', [
            'id'   => $user->id,
            'role' => 'freelancer', // unchanged — never admin
        ]);
    }

    /**
     * An already-configured Google-linked account is returned unchanged.
     */
    public function test_complete_role_is_noop_for_configured_accounts(): void
    {
        $user = $this->googleUser('existing@gmail.com', 'google-existing-123');

        FreelancerProfile::create([
            'user_id'         => $user->id,
            'approval_status' => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/google/complete-role', ['role' => 'employer'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'freelancer');

        // No employer profile was created and the role did not change.
        $this->assertDatabaseMissing('employer_profiles', ['user_id' => $user->id]);
    }

    /* ---------------------------------------------------------------------
     * OAuth callback (web routes) — find-or-create + linking
     * ------------------------------------------------------------------- */

    /**
     * An existing freelancer who registered with email+password is signed in
     * and their account is linked to the Google identity (google_id stored).
     */
    public function test_callback_signs_in_existing_freelancer_and_links_account(): void
    {
        $user = User::create([
            'name'     => 'Existing Freelancer',
            'email'    => 'freelancer@gmail.com',
            'password' => bcrypt('password123'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);
        FreelancerProfile::create(['user_id' => $user->id, 'approval_status' => 'approved']);

        $this->stubGoogleUser([
            'id'    => 'google-existing-999',
            'email' => 'freelancer@gmail.com',
            'name'  => 'Existing Freelancer',
        ]);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect();
        $params = $this->callbackQuery($response);
        $this->assertArrayHasKey('token', $params);
        $this->assertSame('0', $params['new']); // returning user — no role picker
        $this->assertArrayNotHasKey('error', $params);

        $this->assertDatabaseHas('users', [
            'id'        => $user->id,
            'google_id' => 'google-existing-999', // linked to Google
            'role'      => 'freelancer',          // unchanged
        ]);
    }

    /**
     * An existing employer signing in with Google keeps their employer role
     * and profile — Google never downgrades or recreates the account.
     */
    public function test_callback_signs_in_existing_employer_unchanged(): void
    {
        $user = User::create([
            'name'     => 'Existing Employer',
            'email'    => 'employer@gmail.com',
            'password' => bcrypt('password123'),
            'role'     => 'employer',
            'status'   => 'active',
        ]);
        EmployerProfile::create(['user_id' => $user->id, 'company_name' => 'Acme Co']);

        $this->stubGoogleUser([
            'id'    => 'google-employer-888',
            'email' => 'employer@gmail.com',
            'name'  => 'Existing Employer',
        ]);

        $response = $this->get('/auth/google/callback');

        $params = $this->callbackQuery($response);
        $this->assertSame('0', $params['new']);

        $user->refresh();
        $this->assertSame('employer', $user->role);
        $this->assertNotNull($user->employerProfile);
    }

    /**
     * An unknown Google account is created as a brand-new user and flagged
     * new=1 so the frontend shows the freelancer/employer role picker.
     */
    public function test_callback_creates_new_user_for_unmatched_google_account(): void
    {
        $this->stubGoogleUser([
            'id'    => 'brand-new-google-1',
            'email' => 'new.person@gmail.com',
            'name'  => 'New Person',
        ]);

        $response = $this->get('/auth/google/callback');

        $params = $this->callbackQuery($response);
        $this->assertArrayHasKey('token', $params);
        $this->assertSame('1', $params['new']);

        $this->assertDatabaseHas('users', [
            'email'     => 'new.person@gmail.com',
            'google_id' => 'brand-new-google-1',
            'role'      => 'freelancer', // placeholder until the user picks
        ]);
    }

    /**
     * Admin accounts are never accessible through Google — an admin email is
     * bounced back with an error and the account is untouched.
     */
    public function test_callback_rejects_admin_accounts(): void
    {
        User::create([
            'name'     => 'The Admin',
            'email'    => 'admin@dreammore.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);

        $this->stubGoogleUser([
            'id'    => 'google-admin-1',
            'email' => 'admin@dreammore.com',
            'name'  => 'The Admin',
        ]);

        $response = $this->get('/auth/google/callback');

        $params = $this->callbackQuery($response);
        $this->assertSame('Admin accounts must sign in with email and password.', $params['error']);
        $this->assertArrayNotHasKey('token', $params);

        $this->assertDatabaseHas('users', [
            'email'     => 'admin@dreammore.com',
            'google_id' => null, // never linked
        ]);
    }

    /**
     * Cancelling on Google's consent screen (?error=access_denied) returns a
     * clear "you cancelled" message rather than a generic failure.
     */
    public function test_callback_handles_access_denied_gracefully(): void
    {
        $response = $this->get('/auth/google/callback?error=access_denied');

        $response->assertRedirect();
        $params = $this->callbackQuery($response);
        $this->assertSame('You cancelled Google sign-in. No changes were made.', $params['error']);
        $this->assertArrayNotHasKey('token', $params);
    }

    /**
     * A fully missing configuration (no client id/secret) never reaches Google
     * — the visitor is redirected back with an explanatory error.
     */
    public function test_redirect_warns_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => '']);
        config(['services.google.client_secret' => '']);

        $response = $this->get('/auth/google/redirect');

        $response->assertRedirect();
        $params = $this->callbackQuery($response);
        $this->assertStringContainsString('GOOGLE_CLIENT_ID', $params['error']);
    }
}