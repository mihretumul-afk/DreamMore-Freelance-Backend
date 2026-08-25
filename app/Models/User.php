<?php

namespace App\Models;

use App\Traits\HasAdminRoles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasAdminRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'phone',
        'avatar',
        'bio',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The "booted" method of the model.
     * Ensure platform-wide deletion synchronization and data cleanup.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            // Delete posted jobs which cascades job skills, proposals, saved jobs
            $user->jobs()->each(function (Job $job) {
                $job->delete();
            });

            // Clean up freelancer profile and reverse saved freelancer references
            if ($user->freelancerProfile) {
                SavedFreelancer::where('freelancer_profile_id', $user->freelancerProfile->id)->delete();
                $user->freelancerProfile->skills()->detach();
                $user->freelancerProfile->delete();
            }

            // Clean up employer profile
            if ($user->employerProfile) {
                $user->employerProfile->delete();
            }

            // Clean up proposals submitted by freelancer
            $user->proposals()->delete();

            // Clean up items saved by this user
            $user->savedJobs()->delete();
            $user->savedFreelancers()->delete();

            // Clean up credentials, verifications, portfolio items
            $user->credentials()->delete();
            $user->verifications()->delete();
            $user->portfolioItems()->delete();

            // Clean up notifications and tokens
            $user->notifications()->delete();
            $user->tokens()->delete();

            // Clean up admin role assignments
            $user->adminRoles()->detach();
        });
    }

    /**
     * Check if user is a freelancer.
     */
    public function isFreelancer(): bool
    {
        return $this->role === 'freelancer';
    }

    /**
     * Check if user is an employer.
     */
    public function isEmployer(): bool
    {
        return $this->role === 'employer';
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function freelancerProfile(): HasOne
    {
        return $this->hasOne(FreelancerProfile::class);
    }

    public function employerProfile(): HasOne
    {
        return $this->hasOne(EmployerProfile::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'employer_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'freelancer_id');
    }

    public function employerContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'employer_id');
    }

    public function freelancerContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'freelancer_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function savedJobs(): HasMany
    {
        return $this->hasMany(SavedJob::class);
    }

    public function savedFreelancers(): HasMany
    {
        return $this->hasMany(SavedFreelancer::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(PortfolioItem::class)->orderBy('display_order');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class)->orderByDesc('created_at');
    }

    /**
     * Determine whether the freelancer has at least one credential or verification
     * approved by an administrator (or auto-verified via trusted LMS).
     */
    public function hasApprovedCredentials(): bool
    {
        return $this->credentials()->where('status', 'approved')->exists()
            || $this->verifications()->where('status', 'approved')->exists();
    }

    /**
     * Get the overall verification status for the freelancer.
     * Returns: 'approved' | 'pending' | 'rejected' | 'unverified'
     */
    public function verificationStatus(): string
    {
        if ($this->hasApprovedCredentials()) {
            return 'approved';
        }

        $hasPending = $this->credentials()->where('status', 'pending')->exists()
            || $this->verifications()->where('status', 'pending')->exists();

        if ($hasPending) {
            return 'pending';
        }

        $hasRejected = $this->credentials()->where('status', 'rejected')->exists()
            || $this->verifications()->where('status', 'rejected')->exists();

        if ($hasRejected) {
            return 'rejected';
        }

        return 'unverified';
    }
}
