<?php

namespace App\Models;

use App\Enums\VisitSource;
use App\Enums\VisitStatus;
use App\Support\ClinicDay;
use App\Support\QueueCode;
use App\Support\TrackingToken;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable([
    'facility_id', 'patient_id', 'department_id', 'queue_number', 'department_queue_number',
    'department_entered_at', 'status', 'created_by', 'completed_at', 'almost_turn_notified', 'access_pin_hash',
    'assigned_doctor_id', 'doctor_queue_number', 'service_id',
    'source', 'arrived_at', 'arrival_signaled_at', 'arrival_grace_started_at',
])]
#[Hidden(['access_pin_hash'])]
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
            'source' => VisitSource::class,
            'arrived_at' => 'datetime',
            'arrival_signaled_at' => 'datetime',
            'arrival_grace_started_at' => 'datetime',
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
     * The doctor this patient was assigned to. It stays after the patient moves
     * on, as history; isInDoctorQueue() says whether they are in that doctor's
     * line now.
     */
    public function assignedDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_doctor_id');
    }

    /**
     * What the patient was registered to be seen for.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The staff member who registered the visit.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The request this visit was made from, for one that came in remotely.
     */
    public function remoteRequest(): HasOne
    {
        return $this->hasOne(RemoteRequest::class);
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
     * Visits that are still going: not completed and not cancelled.
     *
     * @param  Builder<Visit>  $query
     * @return Builder<Visit>
     */
    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereNotIn('status', [VisitStatus::Completed, VisitStatus::Cancelled]);
    }

    /**
     * The registration number as printed on the ticket and typed into the
     * tracking form ("V027"). Not a secret.
     */
    public function queueCode(): string
    {
        return QueueCode::format($this->queue_number);
    }

    /**
     * The number this visit goes by in its current line: "C-3" in a doctor's
     * own queue, "L-8" once a department has given the patient its own number,
     * and the patient's registration number ("#27") on the first leg, before
     * any transfer. Needs the department loaded to know the letter.
     */
    public function queueLabel(): string
    {
        $number = $this->doctor_queue_number ?? $this->department_queue_number;

        if ($number === null) {
            return "#{$this->queue_number}";
        }

        $prefix = $this->department?->type->prefix();

        return $prefix === null ? "#{$number}" : "{$prefix}-{$number}";
    }

    /**
     * Accepted from home and not yet checked in at the front desk: they hold
     * their place in the line but are not in the building.
     */
    public function isAwaitingArrival(): bool
    {
        return $this->status === VisitStatus::AwaitingArrival;
    }

    /**
     * Whether the patient is waiting in one doctor's own line right now, as
     * opposed to a department's shared one. True from being assigned until they
     * leave that department.
     */
    public function isInDoctorQueue(): bool
    {
        return $this->assigned_doctor_id !== null && $this->doctor_queue_number !== null;
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

    /**
     * The rate-limiter key counting wrong PINs typed against this visit, from
     * anyone. A new PIN starts that count afresh, so it is cleared on reset.
     */
    public function accessPinMissesKey(): string
    {
        return 'track-visit:'.$this->id;
    }

    public function belongsToFacility(int $facilityId): bool
    {
        return $this->facility_id === $facilityId;
    }
}
