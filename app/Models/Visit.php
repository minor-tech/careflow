<?php

namespace App\Models;

use App\Enums\VisitStatus;
use App\Support\ClinicDay;
use App\Support\TrackingToken;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable([
    'facility_id', 'patient_id', 'department_id', 'queue_number', 'department_queue_number',
    'department_entered_at', 'status', 'created_by', 'completed_at', 'almost_turn_notified',
])]
class Visit extends Model
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Visit $visit): void {
            $visit->tracking_token ??= TrackingToken::generate();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'department_entered_at' => 'datetime',
            'completed_at' => 'datetime',
            'almost_turn_notified' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The department the patient is currently with.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The staff member who registered the visit.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The patient's rating of this visit, once they have given one.
     */
    public function feedback(): HasOne
    {
        return $this->hasOne(Feedback::class);
    }

    /**
     * The visit a patient's link points at, or null if there is none. A link
     * only works on the visit's own clinic day: one found in an old message
     * shouldn't keep showing (or accepting anything about) someone's visit.
     */
    public static function findByTrackingToken(string $token): ?self
    {
        return static::query()->where('tracking_token', $token)->registeredToday()->first();
    }

    /**
     * The visit's audit log, oldest first.
     */
    public function events(): HasMany
    {
        return $this->hasMany(VisitEvent::class)->orderBy('id');
    }

    /**
     * Visits registered today, by the clinic's (Nairobi) calendar.
     *
     * @param  Builder<Visit>  $query
     * @return Builder<Visit>
     */
    public function scopeRegisteredToday(Builder $query): Builder
    {
        [$from, $until] = ClinicDay::bounds();

        return $query->where('created_at', '>=', $from)->where('created_at', '<', $until);
    }

    /**
     * The number this visit goes by in its current department: "L-8" once a
     * department has given the patient its own number, and the patient's
     * registration number ("#27") on the first leg, before any transfer.
     * Needs the department loaded to know the letter.
     */
    public function queueLabel(): string
    {
        if ($this->department_queue_number === null) {
            return "#{$this->queue_number}";
        }

        $prefix = $this->department?->type->prefix();

        return $prefix === null ? "#{$this->department_queue_number}" : "{$prefix}-{$this->department_queue_number}";
    }

    /**
     * When the patient joined the queue they are in now: on arrival at the
     * current department, or at registration for a first leg.
     */
    public function joinedQueueAt(): Carbon
    {
        return $this->department_entered_at ?? $this->created_at;
    }

    /**
     * The page where the patient follows this visit, or null for a visit that
     * has no token. Built on APP_URL rather than on whatever address staff
     * happened to open the app at, because it ends up on the patient's phone
     * (in an SMS, in a QR code): a link to "localhost" would be useless there.
     */
    public function trackingUrl(): ?string
    {
        if ($this->tracking_token === null) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').route('tracking.show', $this->tracking_token, absolute: false);
    }

    public function belongsToFacility(int $facilityId): bool
    {
        return $this->facility_id === $facilityId;
    }
}
