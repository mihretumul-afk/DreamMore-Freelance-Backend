<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class MilestoneResource extends JsonResource
{
    private function formatDate(mixed $date): ?string
    {
        if (!$date) {
            return null;
        }
        if ($date instanceof \DateTimeInterface) {
            return $date->toIso8601String();
        }
        try {
            return Carbon::parse($date)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Transform the milestone into an array.
     * Amounts are in Ethiopian Birr (ETB).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'title' => $this->title,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'currency' => 'ETB',
            'status' => $this->status,
            'due_date' => $this->formatDate($this->due_date),
            'submitted_at' => $this->formatDate($this->submitted_at),
            'approved_at' => $this->formatDate($this->approved_at),
            'paid_at' => $this->formatDate($this->paid_at ?? $this->released_at),
            'payment_id' => $this->payment_id ?? null,
            'escrow_funded_at' => $this->formatDate($this->escrow_funded_at ?? $this->funded_at),
            'started_at' => $this->formatDate($this->started_at ?? null),
            'can_start_work' => method_exists($this->resource, 'canStartWork') ? $this->canStartWork() : false,
            'is_funded' => method_exists($this->resource, 'isFunded') ? $this->isFunded() : $this->isEscrowFunded(),
            'attachments' => MilestoneAttachmentResource::collection($this->whenLoaded('attachments')),
            'submissions' => MilestoneSubmissionResource::collection($this->whenLoaded('submissions')),
            'latest_submission' => new MilestoneSubmissionResource($this->whenLoaded('latestSubmission')),
            'submissions_count' => $this->submissions()->count(),
            'created_at' => $this->formatDate($this->created_at),
            'updated_at' => $this->formatDate($this->updated_at),
        ];
    }
}
