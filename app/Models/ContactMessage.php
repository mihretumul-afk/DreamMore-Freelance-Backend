<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContactMessage — an inquiry submitted through the public Contact page
 * (the footer "Contact" button → /contact).
 */
class ContactMessage extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'subject',
        'message',
        'status',
        'read_at',
        'resolution',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'read_at'     => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
    ];

    // ── Status constants ─────────────────────────────────────────────────

    public const STATUS_NEW      = 'new';
    public const STATUS_READ     = 'read';
    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_READ,
        self::STATUS_RESOLVED,
    ];

    // ── Relationships ────────────────────────────────────────────────────

    /**
     * The logged-in user who submitted the message (nullable for guests).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The admin who resolved the message.
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
