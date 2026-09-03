<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformWithdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'amount',
        'currency',
        'status',
        'account_name',
        'account_number',
        'bank_name',
        'bank_code',
        'description',
        'processed_by',
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
