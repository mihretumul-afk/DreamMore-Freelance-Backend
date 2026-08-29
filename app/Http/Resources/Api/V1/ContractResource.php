<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    /**
     * Transform the contract into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'job_id'          => $this->job_id,
            'proposal_id'     => $this->proposal_id,
            'employer_id'     => $this->employer_id,
            'freelancer_id'   => $this->freelancer_id,
            'title'           => $this->title,
            'budget_type'     => $this->budget_type,
            'agreed_rate'     => (float) $this->agreed_rate,
            'total_amount'    => (float) $this->total_amount,
            'status'          => $this->status,
            'terms'           => $this->terms,
            'start_date'      => $this->start_date?->toIso8601String(),
            'end_date'        => $this->end_date?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),

            'job' => $this->whenLoaded('job', fn () => [
                'id'    => $this->job->id,
                'title' => $this->job->title,
                'slug'  => $this->job->slug,
            ]),

            'employer' => $this->whenLoaded('employer', fn () => [
                'id'     => $this->employer->id,
                'name'   => $this->employer->name,
                'avatar' => $this->employer->avatar,
            ]),

            'freelancer' => $this->whenLoaded('freelancer', fn () => [
                'id'     => $this->freelancer->id,
                'name'   => $this->freelancer->name,
                'avatar' => $this->freelancer->avatar,
            ]),

            'milestones' => $this->whenLoaded('milestones', function () {
                return MilestoneResource::collection($this->milestones);
            }),

            'proposal' => $this->whenLoaded('proposal', fn () => [
                'id'         => $this->proposal->id,
                'bid_amount' => (float) $this->proposal->bid_amount,
                'currency'   => $this->proposal->currency,
                'status'     => $this->proposal->status,
            ]),
        ];
    }
}
