<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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
}
