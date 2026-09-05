<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ContactMessageController (admin)
 *
 * Contacts inbox for messages sent through the public Contact page.
 * Every route is guarded with the contacts.manage permission — only
 * helpers/admins granted it (Support Admin by default) can reach this.
 */
class ContactMessageController extends BaseApiController
{
    /**
     * GET /api/v1/admin/contacts
     * Required permission: contacts.manage
     * Lists contact messages, filterable by status and free-text search.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ContactMessage::with('user:id,name,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($sub) use ($request) {
                $term = $request->input('search');
                $sub->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('subject', 'like', "%{$term}%");
            }));

        $messages = $query->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse(
            $messages->items(),
            'Contact messages retrieved successfully.',
            200,
            [
                'current_page' => $messages->currentPage(),
                'last_page'    => $messages->lastPage(),
                'per_page'     => $messages->perPage(),
                'total'        => $messages->total(),
            ]
        );
    }

    /**
     * GET /api/v1/admin/contacts/{contactMessage}
     * Required permission: contacts.manage
     * Opens the message and automatically marks it as read.
     */
    public function show(ContactMessage $contactMessage): JsonResponse
    {
        if ($contactMessage->status === ContactMessage::STATUS_NEW) {
            $contactMessage->update([
                'status'  => ContactMessage::STATUS_READ,
                'read_at' => now(),
            ]);
            $contactMessage->refresh();
        }

        $contactMessage->load(['user:id,name,email', 'resolver:id,name,email']);

        return $this->sendResponse($contactMessage, 'Contact message retrieved successfully.');
    }

    /**
     * PATCH /api/v1/admin/contacts/{contactMessage}
     * Required permission: contacts.manage
     * Marks a message read, resolves it (with an optional resolution note),
     * or reopens a resolved message.
     */
    public function updateStatus(Request $request, ContactMessage $contactMessage): JsonResponse
    {
        $validated = $request->validate([
            'status'     => ['required', 'string', 'in:read,resolved'],
            'resolution' => ['nullable', 'string', 'max:5000'],
        ]);

        $actor = $request->user();

        if ($validated['status'] === ContactMessage::STATUS_RESOLVED) {
            $contactMessage->update([
                'status'      => ContactMessage::STATUS_RESOLVED,
                'resolution'  => $validated['resolution'] ?? null,
                'read_at'     => $contactMessage->read_at ?? now(),
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
            ]);
        } elseif ($contactMessage->status === ContactMessage::STATUS_RESOLVED) {
            // Reopen a resolved message — clears the resolution trail.
            $contactMessage->update([
                'status'      => ContactMessage::STATUS_READ,
                'resolution'  => null,
                'resolved_at' => null,
                'resolved_by' => null,
            ]);
        } else {
            $contactMessage->update([
                'status'  => ContactMessage::STATUS_READ,
                'read_at' => $contactMessage->read_at ?? now(),
            ]);
        }

        return $this->sendResponse(
            $contactMessage->fresh(['user:id,name,email', 'resolver:id,name,email']),
            'Contact message updated successfully.'
        );
    }

    /**
     * DELETE /api/v1/admin/contacts/{contactMessage}
     * Required permission: contacts.manage
     * Removes a contact message from the inbox.
     */
    public function destroy(ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->delete();

        return $this->sendResponse(null, 'Contact message deleted successfully.');
    }
}
