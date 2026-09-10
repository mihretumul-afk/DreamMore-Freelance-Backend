<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Models\Message;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends BaseApiController
{
    /**
     * List conversations (unique participants) for the authenticated user.
     * Shows the last message in each conversation thread.
     */
    /**
     * List conversations (unique participants) for the authenticated user.
     * Shows the last message in each conversation thread.
     */
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        // Retrieve messages where the user is either sender or receiver
        $lastMessages = Message::where('sender_id', $user->id)
            ->orWhere('receiver_id', $user->id)
            ->select(
                'id',
                'contract_id',
                'content',
                'sender_id',
                'receiver_id',
                'created_at',
                'read_at'
            )
            ->orderByDesc('created_at')
            ->get();

        if ($lastMessages->isEmpty()) {
            return $this->sendResponse([], 'Conversations retrieved successfully.');
        }

        // Group by conversation partner and take only the latest message
        $conversationMap = [];
        foreach ($lastMessages as $msg) {
            $partnerId = $msg->sender_id === $user->id
                ? $msg->receiver_id
                : $msg->sender_id;

            if (!isset($conversationMap[$partnerId])) {
                $conversationMap[$partnerId] = $msg;
            }
        }

        // Get unread counts per partner
        $unreadCounts = Message::where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->selectRaw('sender_id, COUNT(*) as unread_count')
            ->groupBy('sender_id')
            ->pluck('unread_count', 'sender_id');

        // Load partner info
        $partnerIds = array_keys($conversationMap);
        $partners = \App\Models\User::whereIn('id', $partnerIds)
            ->select('id', 'name', 'avatar')
            ->get()
            ->keyBy('id');

        $conversations = collect();
        foreach ($conversationMap as $partnerId => $msg) {
            $partner = $partners->get($partnerId);
            $conversations->push([
                'partner' => [
                    'id' => $partnerId,
                    'name' => $partner->name ?? 'User',
                    'avatar' => $partner->avatar ?? null,
                ],
                'last_message' => [
                    'id' => $msg->id,
                    'content' => $msg->content,
                    'sender_id' => $msg->sender_id,
                    'created_at' => $msg->created_at->toISOString(),
                ],
                'unread_count' => $unreadCounts->get($partnerId, 0),
            ]);
        }

        return $this->sendResponse($conversations, 'Conversations retrieved successfully.');
    }

    /**
     * Get messages in a conversation with a specific user.
     */
    public function messages(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();

        $messages = Message::where(function ($query) use ($user, $userId) {
                $query->where('sender_id', $user->id)->where('receiver_id', $userId);
            })->orWhere(function ($query) use ($user, $userId) {
                $query->where('sender_id', $userId)->where('receiver_id', $user->id);
            })
            ->with(['sender:id,name,avatar', 'receiver:id,name,avatar'])
            ->orderBy('created_at', 'asc')
            ->paginate(50);

        // Mark unread messages from the partner as read
        Message::where('sender_id', $userId)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->sendResponse(
            $messages->items(),
            'Messages retrieved successfully.',
            200,
            [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ]
        );
    }

    /**
     * Send a message to a user.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'receiver_id' => 'required|integer|exists:users,id',
            'content' => 'required|string|max:5000',
            'contract_id' => 'nullable|integer|exists:contracts,id',
        ]);

        $contractId = null;

        $message = Message::create([
            'contract_id' => $contractId,
            'sender_id' => $user->id,
            'receiver_id' => $validated['receiver_id'],
            'content' => $validated['content'],
        ]);

        // Notify the receiver (email is sent only if they are offline)
        NotificationService::messageReceived(
            $validated['receiver_id'],
            $user->name,
            $validated['content'],
        );

        $message->load(['sender:id,name,avatar', 'receiver:id,name,avatar']);

        // Broadcast the message in real-time
        try {
            broadcast(new MessageSent($message));
        } catch (\Throwable $e) {
            // WebSocket non-critical fallback
        }

        return $this->sendResponse($message, 'Message sent successfully.', 201);
    }

    /**
     * Mark a specific message as read.
     */
    public function markRead(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();

        if ($message->receiver_id !== $user->id) {
            return $this->sendForbidden('You can only mark your own messages as read.');
        }

        if (is_null($message->read_at)) {
            $message->update(['read_at' => now()]);

            // Broadcast read receipt
            try {
                broadcast(new MessageRead($message->fresh()));
            } catch (\Throwable $e) {
                // WebSocket non-critical fallback
            }
        }

        return $this->sendResponse($message->fresh(), 'Message marked as read.');
    }

    /**
     * Mark all messages in a conversation as read.
     */
    public function markAllRead(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();

        // Single SQL UPDATE instead of iterating in PHP
        Message::where('sender_id', $userId)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->sendResponse(null, 'All messages marked as read.');
    }

    /**
     * Get unread message count for the authenticated user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = Message::where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return $this->sendResponse(['unread_count' => $count], 'Unread count retrieved.');
    }
}
