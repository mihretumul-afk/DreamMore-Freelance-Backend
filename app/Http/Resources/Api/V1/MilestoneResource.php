<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MilestoneResource extends JsonResource
{
    /**
     * Transform the milestone into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'contract_id'   => $this->contract_id,
            'title'         => $this->title,
            'description'   => $this->description,
            'deliverables'  => $this->deliverables,
            'amount'        => (float) $this->amount,
            'status'        => $this->status,
            'due_date'      => $this->due_date?->toIso8601String(),
            'created_by'    => $this->created_by,
            'submitted_at'  => $this->submitted_at?->toIso8601String(),
            'approved_at'   => $this->approved_at?->toIso8601String(),
            'accepted_at'   => $this->accepted_at?->toIso8601String(),
            'started_at'    => $this->started_at?->toIso8601String(),
            'funded_at'     => $this->funded_at?->toIso8601String(),
            'released_at'   => $this->released_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),

            'creator' => $this->whenLoaded('creator', fn () => [
                'id'     => $this->creator->id,
                'name'   => $this->creator->name,
                'avatar' => $this->creator->avatar,
            ]),

            'attachments' => $this->whenLoaded('attachments', function () {
                return $this->attachments->map(fn ($att) => [
                    'id'       => $att->id,
                    'name'     => $att->original_filename,
                    'path'     => $att->stored_path,
                    'type'     => $att->mime_type,
                    'size'     => $att->file_size,
                    'formatted_size' => $att->formatted_size,
                    'is_video' => $att->isVideo(),
                    'is_image' => $att->isImage(),
                    'is_audio' => $att->isAudio(),
                ]);
            }),

            'submissions' => $this->whenLoaded('submissions', function () {
                return $this->submissions->map(fn ($sub) => [
                    'id'            => $sub->id,
                    'description'   => $sub->description,
                    'links'         => $sub->links,
                    'files'         => $sub->files,
                    'status'        => $sub->status,
                    'revision_note' => $sub->revision_note,
                    'submitted_at'  => $sub->submitted_at?->toIso8601String(),
                    'reviewed_at'   => $sub->reviewed_at?->toIso8601String(),
                    'submitter'     => $sub->relationLoaded('submitter') ? [
                        'id'   => $sub->submitter->id,
                        'name' => $sub->submitter->name,
                    ] : null,
                ]);
            }),
        ];
    }
}
