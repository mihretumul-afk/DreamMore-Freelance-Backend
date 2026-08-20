<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillTestQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'skill_test_id',
        'question',
        'options',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'sort_order' => 'integer',
    ];

    public function skillTest(): BelongsTo
    {
        return $this->belongsTo(SkillTest::class);
    }

    /**
     * Get the correct answer index.
     */
    public function getCorrectIndex(): ?int
    {
        foreach ($this->options as $index => $option) {
            if (isset($option['is_correct']) && $option['is_correct']) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Check if the given answer index is correct.
     */
    public function isCorrect(int $selectedIndex): bool
    {
        return $this->getCorrectIndex() === $selectedIndex;
    }
}
