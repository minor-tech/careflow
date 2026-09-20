<?php

namespace App\Models;

use App\Enums\DataRole;
use App\Enums\FacilityStatus;
use App\Enums\FacilityType;
use App\Enums\NotificationChannel;
use App\Enums\OperatingDay;
use App\Enums\OwnershipType;
use App\Enums\UserRole;
use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
])]
class Facility extends Model
{
    /** @use HasFactory<FacilityFactory> */
    use HasFactory;

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

        for ($suffix = 2; static::where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
