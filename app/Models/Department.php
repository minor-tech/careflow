<?php

namespace App\Models;

use App\Enums\DepartmentType;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['facility_id', 'name', 'type', 'is_active'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DepartmentType::class,
            'is_active' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function visitEvents(): HasMany
    {
        return $this->hasMany(VisitEvent::class);
    }

    public function belongsToFacility(int $facilityId): bool
    {
        return $this->facility_id === $facilityId;
    }
}
