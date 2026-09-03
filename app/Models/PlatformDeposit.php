<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformDeposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'amount',
        'currency',
        'status',
        'description',
        'source',
        'deposited_by',
        'completed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    const STATUS_PENDING   = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
}
