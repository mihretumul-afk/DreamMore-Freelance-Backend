<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Models\Contract;
use App\Models\Milestone;
use App\Models\Report;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DisputeController extends BaseApiController
{
    /**
     * List all disputes relevant to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get contracts and milestones belonging to the user
        $contractIds = Contract::where('employer_id', $user->id)
            ->orWhere('freelancer_id', $user->id)
            ->pluck('id');

        $milestoneIds = Milestone::whereIn('contract_id', $contractIds)->pluck('id');

        $query = Report::with('reporter')
            ->where(function ($q) use ($user, $contractIds, $milestoneIds) {
                $q->where('reporter_id', $user->id)
                  ->orWhere(function ($subQ) use ($milestoneIds) {
                      $subQ->where('target_type', 'milestone')
                           ->whereIn('target_id', $milestoneIds);
                  })
                  ->orWhere(function ($subQ) use ($contractIds) {
                      $subQ->where('target_type', 'contract')
                           ->whereIn('target_id', $contractIds);
                  });
            });

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $reports = $query->orderByDesc('created_at')->paginate(15);

        // Transform collection to include parsed notes filtered for user role
        $items = collect($reports->items())->map(function ($report) use ($user) {
            $data = $report->toArray();
            $data['notes_list'] = $this->filterNotesForUser($report->admin_notes, $user);

            if ($report->target_type === 'milestone') {
                $milestone = Milestone::with(['contract.employer', 'contract.freelancer'])->find($report->target_id);
                if ($milestone) {
                    $data['milestone'] = $milestone->toArray();
                    $data['contract']  = $milestone->contract ? $milestone->contract->toArray() : null;
                }
            } elseif ($report->target_type === 'contract') {
                $contract = Contract::with(['employer', 'freelancer'])->find($report->target_id);
                if ($contract) {
                    $data['contract'] = $contract->toArray();
                }
            }

            return $data;
        });

        return $this->sendResponse(
            $items,
            'Disputes retrieved successfully.',
            200,
            [
                'current_page' => $reports->currentPage(),
                'last_page'    => $reports->lastPage(),
                'per_page'     => $reports->perPage(),
                'total'        => $reports->total(),
            ]
        );
    }

    /**
     * Show a single dispute details for the user.
     */
    public function show(Request $request, Report $report): JsonResponse
    {
        $user = $request->user();

        if (!$this->userCanAccessReport($user, $report)) {
            return $this->sendForbidden('You do not have access to this dispute.');
        }

        $report->load('reporter');
        $data = $report->toArray();

        $data['notes_list'] = $this->filterNotesForUser($report->admin_notes, $user);

        if ($report->target_type === 'milestone') {
            $milestone = Milestone::with(['contract.employer', 'contract.freelancer', 'submissions' => fn ($q) => $q->latest()])->find($report->target_id);
            if ($milestone) {
                $data['milestone'] = $milestone->toArray();
                $data['contract']  = $milestone->contract ? $milestone->contract->toArray() : null;
            }
        } elseif ($report->target_type === 'contract') {
            $contract = Contract::with(['employer', 'freelancer'])->find($report->target_id);
            if ($contract) {
                $data['contract'] = $contract->toArray();
            }
        }

        return $this->sendResponse($data, 'Dispute retrieved successfully.');
    }

    /**
     * User (Freelancer or Employer) adds a note/reply to the dispute.
     */
    public function addNote(Request $request, Report $report): JsonResponse
    {
        $user = $request->user();

        if (!$this->userCanAccessReport($user, $report)) {
            return $this->sendForbidden('You do not have access to this dispute.');
        }

        $request->validate([
            'note' => 'required|string|max:5000',
        ]);

        $noteText  = $request->input('note');
        $notesList = ReportController::parseNotes($report->admin_notes);

        $notesList[] = [
            'id'             => (string) Str::uuid(),
            'sender_id'       => $user->id,
            'sender_name'     => $user->name,
            'sender_role'     => $user->role,
            'recipient_type' => $user->role,
            'note'           => $noteText,
            'created_at'     => now()->toIso8601String(),
        ];

        $report->update(['admin_notes' => json_encode($notesList)]);

        // Notify Admins only (user communicates directly with admin mediation)
        NotificationService::notifyAdmins(
            'dispute_update',
            'Dispute Reply Received',
            "{$user->name} ({$user->role}) added a note on dispute #{$report->id}.",
            "/admin/reports/{$report->id}"
        );

        $responseData = $report->fresh()->load('reporter')->toArray();
        $responseData['notes_list'] = $this->filterNotesForUser(json_encode($notesList), $user);

        return $this->sendResponse($responseData, 'Note added successfully.');
    }

    /**
     * Check if user is authorized to access the report.
     */
    private function userCanAccessReport($user, Report $report): bool
    {
        if ($user->role === 'admin' || $report->reporter_id === $user->id) {
            return true;
        }

        if ($report->target_type === 'milestone') {
            $milestone = Milestone::with('contract')->find($report->target_id);
            if ($milestone && $milestone->contract) {
                return $milestone->contract->employer_id === $user->id || $milestone->contract->freelancer_id === $user->id;
            }
        } elseif ($report->target_type === 'contract') {
            $contract = Contract::find($report->target_id);
            if ($contract) {
                return $contract->employer_id === $user->id || $contract->freelancer_id === $user->id;
            }
        }

        return false;
    }

    /**
     * Filter notes so users only see notes intended for them or sent by them.
     */
    private function filterNotesForUser(?string $rawNotes, $user): array
    {
        $allNotes = ReportController::parseNotes($rawNotes);

        if ($user->role === 'admin') {
            return $allNotes;
        }

        return array_values(array_filter($allNotes, function ($note) use ($user) {
            $senderId      = $note['sender_id'] ?? null;
            $recipientType = $note['recipient_type'] ?? 'both';

            // User's own notes are always visible
            if ($senderId === $user->id) {
                return true;
            }

            // Notes from admin/others targeted to 'both' or specifically to user's role
            if ($recipientType === 'both' || $recipientType === $user->role) {
                return true;
            }

            return false;
        }));
    }
}
