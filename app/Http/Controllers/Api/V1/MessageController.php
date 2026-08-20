<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Models\Contract;
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
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get contract IDs where the user is a participant
        $contractIds = Contract::where('employer_id', $user->id)
            ->orWhere('freelancer_id', $user->id)
            ->pluck('id');

        if ($contractIds->isEmpty()) {
            return $this->sendResponse([], 'Conversations retrieved successfully.');
        }

        // Use raw SQL to get the last message per conversation partner
        // without loading ALL messages into PHP memory
        $lastMessages = Message::whereIn('contract_id', $contractIds)
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->select(
                'id',
                'content',
                'sender_id',
                'receiver_id',
                'created_at',
                'read_at'
            )
            ->orderByDesc('created_at')
            ->get();

        // Group by conversation partner and take only the latest
        $conversationMap = [];
        foreach ($lastMessages as $msg) {
            $partnerId = $msg->sender_id === $user->id
                ? $msg->receiver_id
                : $msg->sender_id;

            if (!isset($conversationMap[$partnerId])) {
                $conversationMap[$partnerId] = $msg;
            }
        }

        if (empty($conversationMap)) {
            return $this->sendResponse([], 'Conversations retrieved successfully.');
        }

        // Get unread counts per partner using a single query
        $unreadCounts = Message::whereIn('contract_id', $contractIds)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->selectRaw('sender_id, COUNT(*) as unread_count')
            ->groupBy('sender_id')
            ->pluck('unread_count', 'sender_id');

        // Load partner info in a single query
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
                    'name' => $partner->name ?? 'Unknown',
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
     * Get messages in a conversation with a specific user through a contract.
     */
    public function messages(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();

        // Find contracts connecting these two users
        $contractId = Contract::where(function ($query) use ($user, $userId) {
            $query->where('employer_id', $user->id)->where('freelancer_id', $userId);
        })->orWhere(function ($query) use ($user, $userId) {
            $query->where('employer_id', $userId)->where('freelancer_id', $user->id);
        })->value('id');

        if (!$contractId) {
            // Also allow messages if the users have any active proposal relationship
            return $this->sendError('No active contract or relationship found with this user.', [], 403);
        }

        $messages = Message::where('contract_id', $contractId)
            ->where(function ($query) use ($user, $userId) {
                $query->where(function ($q) use ($user, $userId) {
                    $q->where('sender_id', $user->id)->where('receiver_id', $userId);
                })->orWhere(function ($q) use ($user, $userId) {
                    $q->where('sender_id', $userId)->where('receiver_id', $user->id);
                });
            })
            ->with(['sender:id,name,avatar', 'receiver:id,name,avatar'])
            ->orderBy('created_at', 'asc')
            ->paginate(50);

        // Mark unread messages from the partner as read
        Message::where('contract_id', $contractId)
            ->where('sender_id', $userId)
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
     * Send a message to a user through a contract.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'receiver_id' => 'required|integer|exists:users,id',
            'content' => 'required|string|max:5000',
            'contract_id' => 'nullable|integer|exists:contracts,id',
        ]);

        // Verify the receiver is part of a contract with the sender
        $contractId = $validated['contract_id'] ?? null;

        if ($contractId) {
            $contract = Contract::find($contractId);
            $isParticipant = $contract &&
                ($contract->employer_id === $user->id || $contract->freelancer_id === $user->id) &&
                ($contract->employer_id === $validated['receiver_id'] || $contract->freelancer_id === $validated['receiver_id']);

            if (!$isParticipant) {
                return $this->sendForbidden('You can only message users you have a contract with.');
            }
        } else {
            // Auto-find the contract between these users
            $contract = Contract::where(function ($query) use ($user, $validated) {
                $query->where('employer_id', $user->id)->where('freelancer_id', $validated['receiver_id']);
            })->orWhere(function ($query) use ($user, $validated) {
                $query->where('employer_id', $validated['receiver_id'])->where('freelancer_id', $user->id);
            })->first();

            if (!$contract) {
                return $this->sendForbidden('You can only message users you have a contract with.');
            }
            $contractId = $contract->id;
        }

        $message = Message::create([
            'contract_id' => $contractId,
            'sender_id' => $user->id,
            'receiver_id' => $validated['receiver_id'],
            'content' => $validated['content'],
        ]);

        // Notify the receiver
        NotificationService::messageReceived(
            $validated['receiver_id'],
            $user->name
        );

        $message->load(['sender:id,name,avatar', 'receiver:id,name,avatar']);

        // Broadcast the message in real-time
        broadcast(new MessageSent($message));

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
            broadcast(new MessageRead($message->fresh()));
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
