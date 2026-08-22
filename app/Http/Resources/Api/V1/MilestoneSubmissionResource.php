<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MilestoneSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'milestone_id' => $this->milestone_id,
            'submitted_by' => $this->submitted_by,
            'submitter' => $this->submitter ? [
                'id' => $this->submitter->id,
                'name' => $this->submitter->name,
                'avatar' => $this->submitter->avatar,
            ] : null,
            'description' => $this->description,
            'links' => $this->links ?? [],
            'status' => $this->status,
            'revision_note' => $this->revision_note,
            'submitted_at' => $this->submitted_at?->toIso8601String() ?? $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'reviewer' => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
                'avatar' => $this->reviewer->avatar,
            ] : null,
            'files' => ($this->files ?? collect())->map(function ($file) {
                return [
                    'id' => $file->id,
                    'original_filename' => $file->original_filename,
                    'mime_type' => $file->mime_type,
                    'file_size' => (int) $file->file_size,
                    'created_at' => $file->created_at?->toIso8601String(),
                    'download_url' => url("/api/v1/contracts/{$this->milestone->contract_id}/milestones/{$this->milestone_id}/submissions/{$this->id}/files/{$file->id}/download"),
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
