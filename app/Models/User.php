<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'email', 'password', 'phone', 'title', 'role', 'status',
    'facility_id', 'department_id', 'two_factor_enabled', 'must_change_password',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'two_factor_enabled' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Audit-log entries for things this person did to visits.
     */
    public function visitEvents(): HasMany
    {
        return $this->hasMany(VisitEvent::class);
    }

    /**
     * Visits this person registered.
     */
    public function createdVisits(): HasMany
    {
        return $this->hasMany(Visit::class, 'created_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isReceptionist(): bool
    {
        return $this->role === UserRole::Receptionist;
    }

    public function isDoctor(): bool
    {
        return $this->role === UserRole::Doctor;
    }

    public function isNurse(): bool
    {
        return $this->role === UserRole::Nurse;
    }

    public function isSystemAdmin(): bool
    {
        return $this->role === UserRole::SystemAdmin;
    }

    /**
     * The first letters of the first two names, for the avatar circle: "Amina Wanjiru Njoroge" is "AW".
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->squish()
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function belongsToFacility(int $facilityId): bool
    {
        return $this->facility_id === $facilityId;
    }
}
