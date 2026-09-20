<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'email', 'password', 'phone', 'title', 'role', 'status',
    'facility_id', 'department_id', 'service_id', 'is_on_duty', 'two_factor_enabled', 'must_change_password',
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
            'is_on_duty' => 'boolean',
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
     * A doctor's specialty.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The visits assigned to this doctor, today's and before.
     */
    public function assignedVisits(): HasMany
    {
        return $this->hasMany(Visit::class, 'assigned_doctor_id');
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

    /**
     * The doctors of one department who can be given patients right now:
     * active accounts that have switched themselves on duty.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeAvailableDoctors(Builder $query, int $facilityId, int $departmentId): Builder
    {
        return $query
            ->where('facility_id', $facilityId)
            ->where('department_id', $departmentId)
            ->where('role', UserRole::Doctor)
            ->where('status', UserStatus::Active)
            ->where('is_on_duty', true);
    }

    /**
     * Whether this doctor is taking patients: an explicit switch they or an
     * admin turn on, not something worked out from being signed in.
     */
    public function isOnDuty(): bool
    {
        return $this->is_on_duty === true;
    }

    /**
     * How a doctor is named to patients and colleagues: "Dr. Wanjiku", without
     * doubling the title for someone whose name already starts with it.
     */
    public function doctorName(): string
    {
        return preg_match('/^dr\.?\s/i', $this->name) === 1 ? $this->name : 'Dr. '.$this->name;
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
