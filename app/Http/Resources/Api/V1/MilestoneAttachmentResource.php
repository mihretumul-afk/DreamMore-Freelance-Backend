<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MilestoneAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $contractId = $this->milestone->contract_id ?? $this->whenLoaded('milestone', fn () => $this->milestone->contract_id);
        $milestoneId = $this->milestone_id;

        return [
            'id' => $this->id,
            'milestone_id' => $this->milestone_id,
            'uploader_id' => $this->uploader_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => (int) $this->file_size,
            'file_type' => $this->getFileType(),
            'created_at' => $this->created_at?->toIso8601String(),
            'download_url' => $contractId
                ? url("/api/v1/contracts/{$contractId}/milestones/{$milestoneId}/attachments/{$this->id}/download")
                : null,
            'preview_url' => $this->isPreviewable()
                ? url("/api/v1/contracts/{$contractId}/milestones/{$milestoneId}/attachments/{$this->id}/preview")
                : null,
        ];
    }

    private function getFileType(): string
    {
        $mime = $this->mime_type ?? '';

        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if ($mime === 'application/pdf') return 'pdf';
        if (in_array($mime, ['application/zip', 'application/x-tar', 'application/gzip', 'application/x-rar-compressed', 'application/x-7z-compressed'])) return 'archive';

        return 'document';
    }

    private function isPreviewable(): bool
    {
        $mime = $this->mime_type ?? '';
        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/')
            || $mime === 'application/pdf';
    }
}
