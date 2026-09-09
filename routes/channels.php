<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Chat channels — users can only listen to their own chat channel.
Broadcast::channel('chat.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Notification channels — users can only listen to their own notifications.
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Contract channels — only employer, freelancer, or admin can listen.
Broadcast::channel('contract.{contractId}', function ($user, $contractId) {
    $contract = \App\Models\Contract::find($contractId);
    if (!$contract) {
        return false;
    }

    // Allow if user is employer, freelancer, or admin
    return $user->id === $contract->employer_id
        || $user->id === $contract->freelancer_id
        || $user->role === 'admin';
});

// User-specific contract channels — listen for all contract updates for a user.
Broadcast::channel('contract.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Admin channels — only authorized admins (by role/permission) or super admin can listen.
Broadcast::channel('admin.finance', function ($user) {
    return $user->role === 'admin' && ($user->isSuperAdmin() || $user->hasAnyPermission('finance.view', 'payments.view', 'withdrawals.view', 'finance.manage'));
});

Broadcast::channel('admin.credentials', function ($user) {
    return $user->role === 'admin' && ($user->isSuperAdmin() || $user->hasAnyPermission('users.verify', 'users.view'));
});
