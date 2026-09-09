<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ContactMessageController (public)
 *
 * Stores messages submitted through the public Contact page (the footer
 * "Contact" button → /contact). Guests can submit; signed-in users are
 * linked to their account via user_id.
 */
class ContactMessageController extends BaseApiController
{
    /**
     * POST /api/v1/contact-messages
     * Public, rate-limited. Saves an inquiry for the admin Contacts inbox.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:150'],
            'email'   => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $message = ContactMessage::create([
            'user_id' => $request->user()?->id,
            'name'    => $validated['name'],
            'email'   => $validated['email'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
        ]);

        \App\Services\NotificationService::notifyAdmins(
            'contacts.manage',
            'contact_message_received',
            'New Contact Message Received',
            "Inquiry from {$validated['name']} ({$validated['email']}): {$validated['subject']}",
            '/admin/contacts'
        );

        return $this->sendResponse(
            $message,
            'Message sent successfully. We will get back to you within 24 hours.',
            201
        );
    }
}
