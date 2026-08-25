<?php

namespace App\Traits;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * HasAdminRoles
 *
 * Mixed into the User model. Provides all RBAC helpers for admin sub-roles
 * and granular permissions. The trait only applies to users whose `role`
 * column equals 'admin'; all other users simply return false / empty.
 */
trait HasAdminRoles
{
    // ── Relationship ────────────────────────────────────────────────────

    /**
     * The admin sub-roles held by this user.
     */
    public function adminRoles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'admin_user_roles',
            'user_id',
            'role_id'
        )->withPivot('assigned_by', 'assigned_at');
    }

    // ── Role checks ─────────────────────────────────────────────────────

    /**
     * True when the user is a Super Admin.
     *
     * A user is considered Super Admin if:
     *  1. They explicitly hold the super_admin role, OR
     *  2. They are an admin with NO sub-roles assigned at all
     *     (bootstrap / legacy mode — preserves backward compatibility).
     */
    public function isSuperAdmin(): bool
    {
        if (!$this->isAdmin()) {
            return false;
        }

        // Explicit super_admin role assignment.
        if ($this->adminRoles()
            ->where('slug', Role::SUPER_ADMIN)
            ->where('is_active', true)
            ->exists()) {
            return true;
        }

        // Backward-compatible bootstrap: an admin with zero sub-roles
        // is treated as Super Admin so existing setups are not broken.
        return $this->adminRoles()->count() === 0;
    }

    /**
     * True when the user holds any of the given admin role slugs.
     */
    public function hasAdminRole(string ...$slugs): bool
    {
        if (!$this->isAdmin()) {
            return false;
        }

        return $this->adminRoles()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Return all active admin role slugs this user holds.
     */
    public function getAdminRoleSlugs(): Collection
    {
        if (!$this->isAdmin()) {
            return collect();
        }

        return $this->adminRoles()
            ->where('is_active', true)
            ->pluck('slug');
    }

    // ── Permission checks ────────────────────────────────────────────────

    /**
     * True when the user has the given permission slug.
     *
     * Super Admin always returns true without a DB hit.
     * For other admins, the check unions permissions from all held active roles.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        if (!$this->isAdmin()) {
            return false;
        }

        // Super Admin has everything.
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->adminRoles()
            ->where('is_active', true)
            ->whereHas('permissions', fn ($q) => $q->where('slug', $permissionSlug))
            ->exists();
    }

    /**
     * True when the user has ALL of the supplied permission slugs.
     */
    public function hasAllPermissions(string ...$slugs): bool
    {
        foreach ($slugs as $slug) {
            if (!$this->hasPermission($slug)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when the user has AT LEAST ONE of the supplied permission slugs.
     */
    public function hasAnyPermission(string ...$slugs): bool
    {
        foreach ($slugs as $slug) {
            if ($this->hasPermission($slug)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return all unique permission slugs granted across all held active roles.
     */
    public function getAllPermissions(): Collection
    {
        if (!$this->isAdmin()) {
            return collect();
        }

        if ($this->isSuperAdmin()) {
            return Permission::pluck('slug');
        }

        return $this->adminRoles()
            ->where('is_active', true)
            ->with('permissions:id,slug')
            ->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values();
    }
}
