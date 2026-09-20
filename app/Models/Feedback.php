<?php

namespace App\Models;

use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A patient's rating of one visit, given once and never edited.
 */
#[Fillable(['visit_id', 'patient_id', 'facility_id', 'rating', 'issues', 'comment'])]
class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    /**
     * There is only a created_at.
     */
    public const UPDATED_AT = null;

    /**
     * Ratings at or below this may say what went wrong; above it, there is nothing to diagnose.
     */
    public const LOW_RATING = 3;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'issues' => 'array',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
