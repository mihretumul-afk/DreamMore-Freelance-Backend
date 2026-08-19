<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'document_url',
        'notes',
        'status',
        'reason',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
        'type' => 'identity',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
