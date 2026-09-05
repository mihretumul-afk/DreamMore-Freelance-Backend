<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\EmployerProfile;
use App\Models\FreelancerProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Google OAuth sign-in.
 *
 * Google login is only for creating freelancer/employer accounts — admin
 * accounts are never created or linked through Google; they must keep using
 * email + password.
 *
 * Flow:
 *   1. GET  /auth/google/redirect            (web)  → Google consent screen
 *   2. GET  /auth/google/callback            (web)  → find-or-create user, issue
 *      Sanctum token, bounce back to the SPA with ?token=…&new=0|1
 *   3. POST /api/v1/auth/google/complete-role (api) → brand-new users pick
 *      freelancer/employer and get their profile created
 */
class GoogleAuthController extends BaseApiController
{
    /**
     * Get Socialite Google driver instance with cURL SSL & timeout options configured for local dev.
     */
    protected function getGoogleDriver()
    {
        $driver = Socialite::driver('google');

        $caPath = storage_path('cacert.pem');
        $verify = file_exists($caPath)
            ? $caPath
            : (app()->environment('local') ? false : true);

        $curlOptions = [];
        if (defined('CURLOPT_SSLVERSION') && defined('CURL_SSLVERSION_TLSv1_2')) {
            $curlOptions[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        if (class_exists(\GuzzleHttp\Client::class)) {
            $driver->setHttpClient(new \GuzzleHttp\Client([
                'verify'          => $verify,
                'timeout'         => 30,
                'connect_timeout' => 10,
                'curl'            => $curlOptions,
            ]));
        }

        return $driver;
    }

    /**
     * Redirect the visitor to Google's consent screen.
     */
    public function redirect()
    {
        $clientId = (string) config('services.google.client_id');
        $clientSecret = (string) config('services.google.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
            return redirect()->away($frontend . '/auth/google/callback?error=' . urlencode('Google login is not configured yet. The admin needs to add the GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET values to the backend .env file.'));
        }

        return $this->getGoogleDriver()->stateless()->redirect();
    }

    /**
     * Handle Google's callback: find-or-create the user, issue a Sanctum token
     * and redirect back to the frontend callback page.
     */
    public function callback(Request $request)
    {
        $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

        // Google bounces the visitor back with ?error=access_denied when they
        // cancel on the consent screen — give a clear message instead of a
        // generic failure.
        if ($oauthError = $request->query('error')) {
            $message = $oauthError === 'access_denied'
                ? 'You cancelled Google sign-in. No changes were made.'
                : 'Google sign-in was not completed. Please try again.';
            return redirect()->away($frontend . '/auth/google/callback?error=' . urlencode($message));
        }

        try {
            $attempts = 0;
            $maxAttempts = 3;
            $googleUser = null;

            while ($attempts < $maxAttempts) {
                try {
                    $attempts++;
                    $googleUser = $this->getGoogleDriver()->stateless()->user();
                    break;
                } catch (\Exception $e) {
                    if ($attempts >= $maxAttempts) {
                        throw $e;
                    }
                    Log::warning("[Google Auth] Retry attempt {$attempts}/{$maxAttempts} after error: " . $e->getMessage());
                    usleep(500000);
                }
            }

            $email = strtolower(trim((string) $googleUser->getEmail()));
            $googleId = (string) $googleUser->getId();

            if ($email === '') {
                return redirect()->away($frontend . '/auth/google/callback?error=' . urlencode('Your Google account has no email address. Please use another account.'));
            }

            // Admins are never allowed through Google — email + password only.
            $user = User::where('email', $email)->orWhere('google_id', $googleId)->first();

            if ($user && $user->role === 'admin') {
                return redirect()->away($frontend . '/auth/google/callback?error=' . urlencode('Admin accounts must sign in with email and password.'));
            }

            if ($user && $user->status !== 'active') {
                return redirect()->away($frontend . '/auth/google/callback?error=' . urlencode('Your account is currently ' . $user->status . '.'));
            }

            $isNew = false;

            if ($user) {
                // Existing account (linked by email or google_id) — attach the
                // Google identity if missing, then sign in.
                if (!$user->google_id) {
                    $user->google_id = $googleId;
                }
                if (!$user->avatar && $googleUser->getAvatar()) {
                    $user->avatar = $googleUser->getAvatar();
                }
                $user->last_login_at = now();
                $user->save();
            } else {
                // Brand-new account. The role defaults to 'freelancer' until the
                // user picks freelancer/employer on the frontend (complete-role).
                $user = User::create([
                    'name'           => $googleUser->getName() ?: $email,
                    'email'          => $email,
                    'password'       => Hash::make(Str::random(24)),
                    'google_id'      => $googleId,
                    'avatar'         => $googleUser->getAvatar(),
                    'role'           => 'freelancer',
                    'status'         => 'active',
                    'last_login_at'  => now(),
                ]);
                $isNew = true;
            }

            $token = $user->createToken('google_auth_token')->plainTextToken;

            $query = http_build_query([
                'token' => $token,
                'new'   => $isNew ? '1' : '0',
            ]);

            return redirect()->away($frontend . '/auth/google/callback?' . $query);

        } catch (\Exception $e) {
            Log::error('[Google Auth] Callback failed: ' . $e->getMessage(), [
                'exception' => $e,
                'trace'     => $e->getTraceAsString(),
            ]);

            return redirect()->away($frontend . '/auth/google/callback?error=' . urlencode('Google sign-in failed: ' . $e->getMessage()));
        }
    }

    /**
     * Complete the signup of a brand-new Google user by choosing their role.
     *
     * Only accounts created via Google that don't have a profile yet may use
     * this — it never grants or changes admin roles.
     *
     * POST /api/v1/auth/google/complete-role
     */
    public function completeRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|in:freelancer,employer',
        ]);

        $user = $request->user();

        if (!$user->google_id) {
            return $this->sendError('This account was not created with Google sign-in.', [], 422);
        }

        if ($user->role === 'admin' || $user->freelancerProfile || $user->employerProfile) {
            // Already set up — nothing to do.
            return $this->sendResponse([
                'user' => new UserResource($user->load(['freelancerProfile', 'employerProfile'])),
            ], 'Account already configured.');
        }

        $user->update(['role' => $validated['role']]);

        if ($validated['role'] === 'freelancer') {
            FreelancerProfile::create([
                'user_id'         => $user->id,
                'approval_status' => 'pending',
            ]);
        } else {
            EmployerProfile::create([
                'user_id'      => $user->id,
                'company_name' => $user->name . "'s Company",
            ]);
        }

        $user->load(['freelancerProfile', 'employerProfile']);

        return $this->sendResponse([
            'user' => new UserResource($user),
        ], 'Account created successfully.');
    }
}