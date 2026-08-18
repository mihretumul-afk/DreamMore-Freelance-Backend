<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    /**
     * Transform the contract into an array, including server-side
     * milestone totals so the frontend never trusts client calculations.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $milestones = $this->relationLoaded('milestones')
            ? $this->milestones
            : $this->milestones()->get();

        $totalMilestoneAmount = (float) $milestones->sum('amount');
        $completedMilestones = $milestones->whereIn('status', ['approved', 'paid']);
        $completedAmount = (float) $completedMilestones->sum('amount');
        $milestoneCount = $milestones->count();
        $completedCount = $completedMilestones->count();
        $progressPercent = $milestoneCount > 0
            ? (int) round(($completedCount / $milestoneCount) * 100)
            : 0;

        return [
            'id' => $this->id,
            'job_id' => $this->job_id,
            'proposal_id' => $this->proposal_id,
            'employer_id' => $this->employer_id,
            'freelancer_id' => $this->freelancer_id,
            'title' => $this->title,
            'budget_type' => $this->budget_type,
            'agreed_rate' => (float) $this->agreed_rate,
            'total_amount' => (float) $this->total_amount,
            'currency' => 'ETB',
            'status' => $this->status,
            'start_date' => $this->start_date?->toIso8601String(),
            'end_date' => $this->end_date?->toIso8601String(),
            'job' => $this->whenLoaded('job', function () {
                return [
                    'id' => $this->job->id,
                    'title' => $this->job->title,
                    'slug' => $this->job->slug,
                    'budget_type' => $this->job->budget_type,
                    'min_budget' => (float) $this->job->min_budget,
                    'max_budget' => (float) $this->job->max_budget,
                    'status' => $this->job->status,
                    'location_type' => $this->job->location_type,
                    'location' => $this->job->location,
                    'deadline' => $this->job->deadline?->toIso8601String(),
                ];
            }),
            'employer' => $this->whenLoaded('employer', function () {
                return [
                    'id' => $this->employer->id,
                    'name' => $this->employer->name,
                    'email' => $this->employer->email,
                    'role' => $this->employer->role,
                ];
            }),
            'freelancer' => $this->whenLoaded('freelancer', function () {
                return [
                    'id' => $this->freelancer->id,
                    'name' => $this->freelancer->name,
                    'email' => $this->freelancer->email,
                    'role' => $this->freelancer->role,
                ];
            }),
            'milestones' => MilestoneResource::collection($milestones),
            'summary' => [
                'total_milestone_amount' => $totalMilestoneAmount,
                'completed_milestone_amount' => $completedAmount,
                'remaining_milestone_amount' => round($totalMilestoneAmount - $completedAmount, 2),
                'milestones_count' => $milestoneCount,
                'completed_milestones_count' => $completedCount,
                'pending_milestones_count' => $milestoneCount - $completedCount,
                'progress_percent' => $progressPercent,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
