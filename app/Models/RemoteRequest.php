<?php

namespace App\Models;

use App\Enums\RemoteRequestSource;
use App\Enums\RemoteRequestStatus;
use App\Support\ClinicDay;
use App\Support\TrackingToken;
use Database\Factories\RemoteRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone asking, from a public page, to be put in a facility's queue: from
 * home, or from inside the building on their own phone. It is not a visit and
 * they are not yet a patient: accepting it creates both.
 */
#[Fillable([
    'facility_id', 'name', 'phone', 'service_id', 'preferred_doctor_id', 'source', 'requested_arrival',
    'status', 'public_code', 'declined_reason', 'visit_id',
    'recommended_arrival_from', 'recommended_arrival_until', 'reviewed_by', 'reviewed_at',
])]
class RemoteRequest extends Model
{
    /** @use HasFactory<RemoteRequestFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Its own secret, made the way a visit's tracking token is, and never
        // derived from the request's id or the phone number.
        static::creating(function (RemoteRequest $request): void {
            $request->public_code ??= TrackingToken::generate();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => RemoteRequestSource::class,
            'status' => RemoteRequestStatus::class,
            'recommended_arrival_from' => 'datetime',
            'recommended_arrival_until' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The doctor the requester asked for, where the facility lets them.
     */
    public function preferredDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preferred_doctor_id');
    }

    /**
     * The visit accepting it created.
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Requests made today, by the clinic's (Nairobi) calendar.
     *
     * @param  Builder<RemoteRequest>  $query
     * @return Builder<RemoteRequest>
     */
    public function scopeMadeToday(Builder $query): Builder
    {
        [$from, $until] = ClinicDay::bounds();

        return $query->where('created_at', '>=', $from)->where('created_at', '<', $until);
    }

    /**
     * @param  Builder<RemoteRequest>  $query
     * @return Builder<RemoteRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RemoteRequestStatus::Pending);
    }

    public function isSelfCheckin(): bool
    {
        return $this->source === RemoteRequestSource::SelfCheckin;
    }

    /**
     * The time of day they said they would arrive, as staff read it: "10:30 AM".
     */
    public function requestedArrivalLabel(): string
    {
        return Carbon::createFromFormat('H:i:s', $this->requested_arrival)->format('g:i A');
    }

    /**
     * Where the requester follows their request. Built on APP_URL, like a
     * visit's tracking link, because it ends up on their phone.
     */
    public function statusUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('remote.status', $this->public_code, absolute: false);
    }
}
