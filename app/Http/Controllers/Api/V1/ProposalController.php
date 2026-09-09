<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\ProposalRequest;
use App\Http\Requests\Api\V1\ProposalStatusRequest;
use App\Http\Resources\Api\V1\ProposalResource;
use App\Models\Contract;
use App\Models\Job;
use App\Models\Proposal;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProposalController extends BaseApiController
{
    /**
     * Statuses a freelancer may still edit or withdraw their proposal from.
     */
    private const FREELANCER_EDITABLE_STATUSES = ['pending', 'shortlisted'];

    /**
     * Freelancer lists their own proposals (admins see all proposals).
     */
    public function index(Request $request): JsonResponse
    {
        $guard = $this->requireFreelancer($request);
        if ($guard) {
            return $guard;
        }

        $proposals = Proposal::with(['job', 'freelancer.freelancerProfile.skills', 'portfolioItems'])
            ->when($request->user()->role !== 'admin', fn ($query) => $query->where('freelancer_id', $request->user()->id))
            ->orderByDesc('created_at')
            ->paginate(15);

        return $this->sendResponse(
            ProposalResource::collection($proposals)->resolve($request),
            'Proposals retrieved successfully.',
            200,
            $this->paginationMeta($proposals)
        );
    }

    /**
     * Freelancer views one of their own proposals.
     */
    public function show(Request $request, Proposal $proposal): JsonResponse
    {
        $guard = $this->requireFreelancer($request);
        if ($guard) {
            return $guard;
        }

        $proposal = $this->loadOwnedProposal($request, $proposal);
        if ($proposal instanceof JsonResponse) {
            return $proposal;
        }

        $proposal->load(['job', 'freelancer.freelancerProfile.skills', 'contract', 'portfolioItems']);

        return $this->sendResponse(
            new ProposalResource($proposal),
            'Proposal retrieved successfully.'
        );
    }

    /**
     * Freelancer submits a proposal for an open job.
     */
    public function store(ProposalRequest $request, Job $job): JsonResponse
    {
        $guard = $this->requireFreelancer($request);
        if ($guard) {
            return $guard;
        }

        if ($request->user()->role === 'freelancer') {
            $profile = $request->user()->freelancerProfile;
            if (!$profile || $profile->approval_status !== 'approved') {
                return $this->sendForbidden('Your account is pending admin approval. You cannot submit proposals until your profile is approved.');
            }
            if (!$request->user()->hasApprovedCredentials()) {
                return $this->sendForbidden('Your credentials must be approved by an administrator before you can apply for jobs.');
            }
        }

        if ($job->status !== 'open') {
            return $this->sendError('Proposals can only be submitted for open jobs.', [], 422);
        }

        $hasActive = Proposal::where('job_id', $job->id)
            ->where('freelancer_id', $request->user()->id)
            ->whereNotIn('status', ['rejected', 'withdrawn'])
            ->exists();

        if ($hasActive) {
            return $this->sendError('You already have an active proposal for this job.', [], 422);
        }

        $validated = $request->validated();
        $portfolioItemIds = $validated['portfolio_item_ids'] ?? [];
        unset($validated['portfolio_item_ids']);

        $proposal = DB::transaction(function () use ($validated, $portfolioItemIds, $job, $request) {
            $proposal = Proposal::create(array_merge($validated, [
                'job_id' => $job->id,
                'freelancer_id' => $request->user()->id,
            ]));

            if (!empty($portfolioItemIds)) {
                $proposal->portfolioItems()->sync($portfolioItemIds);
            }

            $job->increment('proposals_count');

            return $proposal;
        });

        // Notify the employer about the new proposal
        NotificationService::newProposal(
            $job->employer_id,
            $request->user()->name,
            $job->title,
            $job->id
        );

        // Notify admins about the new proposal
        NotificationService::notifyAdmins(
            'jobs.view',
            'admin_proposal_created',
            'New Proposal Submitted',
            "{$request->user()->name} submitted a proposal for '{$job->title}'.",
            "/employer/jobs/{$job->id}/proposals"
        );

        $proposal->load(['job', 'freelancer.freelancerProfile.skills', 'portfolioItems']);

        return $this->sendResponse(
            new ProposalResource($proposal),
            'Proposal submitted successfully.',
            201
        );
    }

    /**
     * Freelancer updates their own proposal while it is still actionable.
     */
    public function update(ProposalRequest $request, Proposal $proposal): JsonResponse
    {
        $guard = $this->requireFreelancer($request);
        if ($guard) {
            return $guard;
        }

        $proposal = $this->loadOwnedProposal($request, $proposal);
        if ($proposal instanceof JsonResponse) {
            return $proposal;
        }

        if (!in_array($proposal->status, self::FREELANCER_EDITABLE_STATUSES, true)) {
            return $this->sendError(
                "Only pending or shortlisted proposals can be updated. Current status: '{$proposal->status}'.",
                [],
                422
            );
        }

        $validated = $request->validated();
        $portfolioItemIds = $validated['portfolio_item_ids'] ?? null;
        unset($validated['portfolio_item_ids']);

        $proposal->update($validated);

        if (!is_null($portfolioItemIds)) {
            $proposal->portfolioItems()->sync($portfolioItemIds);
        }

        $proposal->load(['job', 'freelancer.freelancerProfile.skills', 'portfolioItems']);
        $proposal->refresh();

        return $this->sendResponse(
            new ProposalResource($proposal),
            'Proposal updated successfully.'
        );
    }

    /**
     * Freelancer withdraws their own proposal before it is accepted/rejected.
     */
    public function withdraw(ProposalStatusRequest $request, Proposal $proposal): JsonResponse
    {
        $guard = $this->requireFreelancer($request);
        if ($guard) {
            return $guard;
        }

        $proposal = $this->loadOwnedProposal($request, $proposal);
        if ($proposal instanceof JsonResponse) {
            return $proposal;
        }

        if (!in_array($proposal->status, self::FREELANCER_EDITABLE_STATUSES, true)) {
            return $this->sendError(
                "Only pending or shortlisted proposals can be withdrawn. Current status: '{$proposal->status}'.",
                [],
                422
            );
        }

        $proposal->update(['status' => 'withdrawn']);

        $proposal->load(['job', 'freelancer']);
        $proposal->refresh();

        return $this->sendResponse(
            new ProposalResource($proposal),
            'Proposal withdrawn successfully.'
        );
    }

    /**
     * Employer lists all proposals submitted to their own job.
     */
    public function jobProposals(Request $request, Job $job): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $job = $this->loadOwnedJob($request, $job);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        $proposals = $job->proposals()
            ->with(['job', 'freelancer.freelancerProfile.skills', 'portfolioItems'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return $this->sendResponse(
            ProposalResource::collection($proposals)->resolve($request),
            'Proposals retrieved successfully.',
            200,
            $this->paginationMeta($proposals)
        );
    }

    /**
     * Employer views a single proposal submitted to their own job.
     */
    public function showJobProposal(Request $request, Job $job, Proposal $proposal): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $job = $this->loadOwnedJob($request, $job);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        $proposal = $this->findScopedProposal($job, $proposal);
        if ($proposal instanceof JsonResponse) {
            return $proposal;
        }

        $proposal->load(['job', 'freelancer.freelancerProfile.skills', 'contract', 'portfolioItems']);

        return $this->sendResponse(
            new ProposalResource($proposal),
            'Proposal retrieved successfully.'
        );
    }

    /**
     * Employer shortlists a pending proposal.
     */
    public function shortlist(ProposalStatusRequest $request, Job $job, Proposal $proposal): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $job = $this->loadOwnedJob($request, $job);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        $proposal = $this->findScopedProposal($job, $proposal);
        if ($proposal instanceof JsonResponse) {
            return $proposal;
        }

        if ($proposal->status !== 'pending') {
            if ($proposal->status === 'accepted') {
                return $this->sendError('This proposal has already been accepted and hired.', [], 422);
            }
            return $this->sendError(
                "Only pending proposals can be shortlisted. Current status: '{$proposal->status}'.",
                [],
                422
            );
        }

        $proposal->update(['status' => 'shortlisted']);

        // Notify the freelancer
        NotificationService::proposalStatusChanged(
            $proposal->freelancer_id,
            'shortlisted',
            $job->title,
            $job->id
        );

        $proposal->load(['job', 'freelancer']);
        $proposal->refresh();

        return $this->sendResponse(
            new ProposalResource($proposal),
            'Proposal shortlisted successfully.'
        );
    }

    /**
     * Employer rejects a pending or shortlisted proposal.
     */
    public function reject(ProposalStatusRequest $request, Job $job, Proposal $proposal): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $job = $this->loadOwnedJob($request, $job);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        $proposal = $this->findScopedProposal($job, $proposal);
        if ($proposal instanceof JsonResponse) {
            return $proposal;
        }

        if ($proposal->status === 'accepted') {
            return $this->sendError('This proposal has already been accepted and cannot be rejected.', [], 422);
        }

        if (!in_array($proposal->status, self::FREELANCER_EDITABLE_STATUSES, true)) {
            return $this->sendError(
                "Only pending or shortlisted proposals can be rejected. Current status: '{$proposal->status}'.",
                [],
                422
            );
        }

        $proposal->update(['status' => 'rejected']);

        // Notify the freelancer
        NotificationService::proposalStatusChanged(
            $proposal->freelancer_id,
            'rejected',
            $job->title,
            $job->id
        );

        $proposal->load(['job', 'freelancer']);
        $proposal->refresh();

        return $this->sendResponse(
            new ProposalResource($proposal),
            'Proposal rejected successfully.'
        );
    }

    /**
     * Employer accepts a proposal.
     */
    public function accept(ProposalStatusRequest $request, Job $job, Proposal $proposal): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $job = $this->loadOwnedJob($request, $job);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        $proposal = $this->findScopedProposal($job, $proposal);
        if ($proposal instanceof JsonResponse) {
            return $proposal;
        }

        if ($proposal->status === 'accepted') {
            return $this->sendResponse(
                new ProposalResource($proposal),
                'This proposal has already been accepted.'
            );
        }

        if (!in_array($proposal->status, self::FREELANCER_EDITABLE_STATUSES, true)) {
            return $this->sendError(
                "Only pending or shortlisted proposals can be accepted. Current status: '{$proposal->status}'.",
                [],
                422
            );
        }

        if ($proposal->freelancer && $proposal->freelancer->role === 'freelancer') {
            $freelancerProfile = $proposal->freelancer->freelancerProfile;
            if (!$freelancerProfile || $freelancerProfile->approval_status !== 'approved') {
                return $this->sendError('The freelancer must be approved before this proposal can be accepted.', [], 422);
            }
        }

        $contract = DB::transaction(function () use ($job, $proposal, $request) {
            $proposal->update(['status' => 'accepted']);

            $job->proposals()
                ->where('id', '!=', $proposal->id)
                ->active()
                ->update(['status' => 'rejected']);

            if ($job->status === 'open') {
                $job->update(['status' => 'in_progress']);
            }

            // Auto-create contract
            $contract = Contract::create([
                'job_id'        => $job->id,
                'proposal_id'   => $proposal->id,
                'employer_id'   => $job->employer_id,
                'freelancer_id' => $proposal->freelancer_id,
                'title'         => $job->title,
                'budget_type'   => $job->budget_type ?? 'fixed',
                'agreed_rate'   => $proposal->bid_amount,
                'total_amount'  => $proposal->bid_amount,
                'status'        => Contract::STATUS_PENDING,
            ]);

            return $contract;
        });

        // Notify freelancer about proposal acceptance + contract
        NotificationService::proposalStatusChanged(
            $proposal->freelancer_id,
            'accepted',
            $job->title,
            $job->id
        );

        NotificationService::contractCreated(
            $proposal->freelancer_id,
            $job->title,
            'freelancer',
            $contract->id
        );

        AuditService::record(
            $request->user(),
            'proposal.accepted',
            'Proposal',
            $proposal->id,
            "Accepted proposal #{$proposal->id} for job '{$job->title}'. Contract #{$contract->id} created."
        );

        $proposal->load(['job', 'freelancer']);
        $proposal->refresh();

        return $this->sendResponse(
            new ProposalResource($proposal),
            'Proposal accepted successfully. Contract created.'
        );
    }

    /**
     * Only freelancers and admins may submit/update/withdraw proposals.
     */
    private function requireFreelancer(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only freelancers can manage proposals.');
        }

        return null;
    }

    /**
     * Only employers and admins may manage proposals on their own jobs.
     */
    private function requireEmployer(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'employer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only employers can manage proposals.');
        }

        return null;
    }

    /**
     * Ensure the proposal belongs to the authenticated freelancer (admins bypass).
     */
    private function loadOwnedProposal(Request $request, Proposal $proposal): Proposal|JsonResponse
    {
        $user = $request->user();

        if ($proposal->freelancer_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this proposal.');
        }

        return $proposal;
    }

    /**
     * Ensure the job belongs to the authenticated employer (admins bypass).
     */
    private function loadOwnedJob(Request $request, Job $job): Job|JsonResponse
    {
        $user = $request->user();

        if ($job->employer_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this job.');
        }

        return $job;
    }

    /**
     * Ensure the URL proposal actually belongs to the URL job.
     * Returns 404 when it does not, to avoid leaking existence.
     */
    private function findScopedProposal(Job $job, Proposal $proposal): Proposal|JsonResponse
    {
        if ($proposal->job_id !== $job->id) {
            return $this->sendError('Proposal not found for this job.', [], 404);
        }

        return $proposal;
    }

    /**
     * Extract pagination info for the response meta.
     *
     * @return array<string, mixed>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
