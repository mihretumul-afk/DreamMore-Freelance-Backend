<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    protected $fillable = [
        'reporter_id',
        'target_type',
        'target_id',
        'reason',
        'description',
        'status',
        'resolution',
        'admin_notes',
        'resolution_type',
        'resolution_amount',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at'       => 'datetime',
        'resolution_amount' => 'decimal:2',
    ];

    protected $attributes = [
        'status'          => 'pending',
        'resolution_type' => 'pending',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** Get the related contract (if dispute is on a milestone). */
    public function contract()
    {
        if ($this->target_type === 'milestone') {
            $milestone = Milestone::find($this->target_id);
            return $milestone ? $milestone->contract() : null;
        }
        return null;
    }

    /** Get the related milestone (if dispute is on a milestone). */
    public function milestone()
    {
        if ($this->target_type === 'milestone') {
            return Milestone::find($this->target_id);
        }
        return null;
    }
}
