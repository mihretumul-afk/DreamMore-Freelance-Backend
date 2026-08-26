<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Freelancer profile approval status (if loaded).
        $profileApproval = $this->freelancerProfile?->approval_status ?? null;
        $hasApprovedCreds = $this->hasApprovedCredentials();

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'is_active' => $this->status === 'active',
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'is_verified' => $hasApprovedCreds,
            'verification_status' => $this->verificationStatus(),
            'has_approved_credentials' => $hasApprovedCreds,
            // Combined approval: BOTH profile approved AND credentials approved.
            // This matches the EnsureVerifiedFreelancer middleware dual-check.
            'is_fully_approved' => $profileApproval === 'approved' && $hasApprovedCreds,
            'profile_approval_status' => $profileApproval,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'freelancer_profile' => $this->whenLoaded('freelancerProfile'),
            'employer_profile' => $this->whenLoaded('employerProfile'),
        ];

        // Admin RBAC data — included when the user is an admin and the
        // relationships are loaded (auth/me loads them for admin users).
        if ($this->isAdmin() && $this->relationLoaded('adminRoles')) {
            $data['admin_roles'] = $this->adminRoles->map(fn ($role) => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'is_system' => $role->is_system,
            ]);
            $data['is_super_admin'] = $this->isSuperAdmin();
            $data['all_permissions'] = $this->getAllPermissions()->values()->all();
        }

        return $data;
    }
}
