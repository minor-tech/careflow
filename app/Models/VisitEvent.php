<?php

namespace App\Models;

use App\Enums\VisitEventType;
use Database\Factories\VisitEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One step of a visit's journey. Append-only: rows are written once and never
 * changed or removed, so the log can be trusted to reconstruct what happened.
 */
#[Fillable(['visit_id', 'department_id', 'event', 'user_id', 'meta'])]
class VisitEvent extends Model
{
    /** @use HasFactory<VisitEventFactory> */
    use HasFactory;

    /**
     * There is only a created_at.
     */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Visit events are append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Visit events are append-only and cannot be deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => VisitEventType::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The staff member who triggered the event.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
