<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ── Built-in system role slugs ──────────────────────────────────────

    public const SUPER_ADMIN   = 'super_admin';
    public const SUPPORT_ADMIN = 'support_admin';
    public const FINANCE_ADMIN = 'finance_admin';
    public const DISPUTE_ADMIN = 'dispute_admin';

    public const SYSTEM_ROLES = [
        self::SUPER_ADMIN,
        self::SUPPORT_ADMIN,
        self::FINANCE_ADMIN,
        self::DISPUTE_ADMIN,
    ];

    // ── Relationships ───────────────────────────────────────────────────

    /**
     * Permissions granted to this role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permissions',
            'role_id',
            'permission_id'
        )->withPivot('granted_by', 'granted_at');
    }

    /**
     * Admin users who hold this role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'admin_user_roles',
            'role_id',
            'user_id'
        )->withPivot('assigned_by', 'assigned_at');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    public function isSystem(): bool
    {
        return $this->is_system;
    }

    public function isSuperAdmin(): bool
    {
        return $this->slug === self::SUPER_ADMIN;
    }

    /**
     * Check whether this role has a specific permission slug.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        return $this->permissions()->where('slug', $permissionSlug)->exists();
    }

    // ── Scopes ──────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }
}
