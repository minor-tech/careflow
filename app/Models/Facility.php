<?php

namespace App\Models;

use App\Enums\DataRole;
use App\Enums\FacilityStatus;
use App\Enums\FacilityType;
use App\Enums\NotificationChannel;
use App\Enums\OperatingDay;
use App\Enums\OwnershipType;
use App\Enums\RemoteQueueAvailability;
use App\Enums\RemoteRequestSource;
use App\Enums\RemoteRequestStatus;
use App\Enums\UserRole;
use App\Support\ClinicDay;
use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'slug', 'facility_type', 'license_number', 'ownership_type',
    'county', 'sub_county', 'address', 'latitude', 'longitude',
    'phone', 'alt_phone', 'email', 'website',
    'operating_days', 'opens_at', 'closes_at', 'is_24hr', 'doctors_count', 'consultation_rooms',
    'notification_channels', 'sms_sender_id',
    'data_role', 'odpc_registration_no',
    'dpa_accepted_at', 'patient_consent_confirmed_at', 'terms_accepted_at', 'privacy_accepted_at', 'signature_name',
    'status',
    'remote_queue_enabled', 'remote_queue_max_pending', 'remote_queue_accept_until', 'remote_queue_grace_minutes',
    'remote_queue_allow_doctor_choice', 'remote_queue_allow_service_choice', 'self_checkin_enabled',
])]
class Facility extends Model
{
    /**
     * Words that already mean something at the top of the site (a page, a
     * section, a file), so a facility's public address can never be one of them.
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = [
        'about', 'contact', 'login', 'logout', 'register', 'register-facility', 'forgot-password', 'reset-password',
        'verify-email', 'email', 'confirm-password', 'password', 'dashboard', 'profile', 'facility', 'facilities',
        'queue', 'staff', 'departments', 'services', 'notifications', 'analytics', 'settings', 'patients', 'visits',
        'remote-requests', 'system', 'up', 'storage', 'build', 'fonts', 'images', 'assets', 'api', 'admin', 'help',
        'privacy', 'terms', 't', 'r',
    ];

    /** @use HasFactory<FacilityFactory> */
    use HasFactory;

    /**
     * The same defaults the database gives the remote queue settings, so a
     * facility that has just been created (and not yet reloaded) behaves as
     * one that was loaded: without them a limit reads as null, and "no
     * requests waiting" is not below it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'remote_queue_enabled' => false,
        'remote_queue_max_pending' => 20,
        'remote_queue_grace_minutes' => 10,
        'remote_queue_allow_doctor_choice' => false,
        'remote_queue_allow_service_choice' => true,
        'self_checkin_enabled' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (Facility $facility): void {
            $facility->slug ??= static::uniqueSlugFor($facility->name);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'facility_type' => FacilityType::class,
            'ownership_type' => OwnershipType::class,
            'data_role' => DataRole::class,
            'status' => FacilityStatus::class,
            'operating_days' => 'array',
            'notification_channels' => 'array',
            'is_24hr' => 'boolean',
            'remote_queue_enabled' => 'boolean',
            'remote_queue_max_pending' => 'integer',
            'remote_queue_grace_minutes' => 'integer',
            'remote_queue_allow_doctor_choice' => 'boolean',
            'remote_queue_allow_service_choice' => 'boolean',
            'self_checkin_enabled' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'dpa_accepted_at' => 'datetime',
            'patient_consent_confirmed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function remoteRequests(): HasMany
    {
        return $this->hasMany(RemoteRequest::class);
    }

    public function patientNotifications(): HasMany
    {
        return $this->hasMany(PatientNotification::class);
    }

    /**
     * The facility's first admin: the account created on the registration.
     */
    public function admin(): HasOne
    {
        return $this->hasOne(User::class)->where('role', UserRole::Admin)->orderBy('id');
    }

    /**
     * The system admin who approved or rejected the registration.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Whether the facility opted into a channel at registration. Messages are
     * only sent (and charged for) over channels it chose.
     */
    public function usesChannel(NotificationChannel $channel): bool
    {
        return in_array($channel->value, $this->notification_channels ?? [], true);
    }

    public function isPendingReview(): bool
    {
        return $this->status === FacilityStatus::PendingReview;
    }

    /**
     * The address where a patient types their queue code and PIN to follow a
     * visit. Built on APP_URL, like a visit's tracking link, because it is
     * printed on tickets that patients carry off.
     */
    public function trackingEntryUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('tracking.entry', $this->slug, absolute: false);
    }

    /**
     * Whether the facility is taking remote queue requests right now, worked
     * out live: switched on, not past today's cut-off time (the clinic's own
     * clock), and with fewer requests from home waiting for review today than
     * its limit. Nothing is cached, so a request that fills the last place
     * closes it at once.
     */
    public function remoteQueueAvailability(): RemoteQueueAvailability
    {
        if (! $this->remote_queue_enabled) {
            return RemoteQueueAvailability::Off;
        }

        if ($this->isPastRemoteCutoff()) {
            return RemoteQueueAvailability::Closed;
        }

        return $this->pendingRemoteRequestsToday() >= $this->remote_queue_max_pending
            ? RemoteQueueAvailability::Full
            : RemoteQueueAvailability::Open;
    }

    /**
     * Whether today's cut-off for new requests from home has passed. Compared
     * to the minute: "16:00" still takes requests all through 16:00.
     */
    public function isPastRemoteCutoff(): bool
    {
        if ($this->remote_queue_accept_until === null) {
            return false;
        }

        return ClinicDay::now()->format('H:i') > substr((string) $this->remote_queue_accept_until, 0, 5);
    }

    /**
     * Requests from home made today that are still waiting for a decision:
     * the number the limit is measured against.
     */
    public function pendingRemoteRequestsToday(): int
    {
        return $this->remoteRequests()
            ->where('source', RemoteRequestSource::Remote)
            ->pending()
            ->madeToday()
            ->count();
    }

    /**
     * Facilities open for remote requests right now, by the same rules as
     * remoteQueueAvailability() but in one query, so a directory can filter on it.
     *
     * @param  Builder<Facility>  $query
     * @return Builder<Facility>
     */
    public function scopeOpenForRemoteQueue(Builder $query): Builder
    {
        [$from, $until] = ClinicDay::bounds();

        return $query
            ->where('remote_queue_enabled', true)
            ->where(fn (Builder $cutoff) => $cutoff
                ->whereNull('remote_queue_accept_until')
                ->orWhereRaw('substr(remote_queue_accept_until, 1, 5) >= ?', [ClinicDay::now()->format('H:i')]))
            ->whereRaw(
                '(select count(*) from remote_requests where remote_requests.facility_id = facilities.id and remote_requests.source = ? and remote_requests.status = ? and remote_requests.created_at >= ? and remote_requests.created_at < ?) < remote_queue_max_pending',
                [RemoteRequestSource::Remote->value, RemoteRequestStatus::Pending->value, $from->toDateTimeString(), $until->toDateTimeString()],
            );
    }

    /**
     * The address of the page where someone already in the building checks
     * themselves in. Built on APP_URL, like a tracking link, because it is
     * printed on a QR code that goes up on a wall.
     */
    public function selfCheckinUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('checkin.form', $this->slug, absolute: false);
    }

    public function isActive(): bool
    {
        return $this->status === FacilityStatus::Active;
    }

    public function operatingHoursLabel(): string
    {
        if ($this->is_24hr) {
            return 'Open 24 hours';
        }

        if ($this->opens_at === null || $this->closes_at === null) {
            return 'Not set';
        }

        return Carbon::parse($this->opens_at)->format('g:i A').' to '.Carbon::parse($this->closes_at)->format('g:i A');
    }

    public function operatingDaysLabel(): string
    {
        $days = array_map(
            fn (string $day): string => OperatingDay::tryFrom($day)?->label() ?? $day,
            $this->operating_days ?? [],
        );

        return $days === [] ? 'Not set' : implode(', ', $days);
    }

    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'facility';
        $slug = $base;

        for ($suffix = 2; in_array($slug, self::RESERVED_SLUGS, true) || static::where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
