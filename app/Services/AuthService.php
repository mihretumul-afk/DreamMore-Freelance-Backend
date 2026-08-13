<?php

namespace App\Services;

use App\Models\User;
use App\Models\FreelancerProfile;
use App\Models\EmployerProfile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Register a new user and create their role profile.
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name'     => $data['name'],
            'email'    => strtolower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'role'     => $data['role'],
            'status'   => 'active',
            'phone'    => $data['phone'] ?? null,
            'bio'      => $data['bio'] ?? null,
        ]);

        if ($user->role === 'freelancer') {
            FreelancerProfile::create([
                'user_id' => $user->id,
            ]);
        } elseif ($user->role === 'employer') {
            EmployerProfile::create([
                'user_id' => $user->id,
                'company_name' => $user->name . "'s Company",
            ]);
        }

        $user->load(['freelancerProfile', 'employerProfile']);
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    /**
     * Authenticate credentials and return user with token.
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'account' => ['Your account is currently ' . $user->status . '.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);
        $user->load(['freelancerProfile', 'employerProfile']);
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    /**
     * Revoke active user tokens.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
