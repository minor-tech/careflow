<?php

namespace App\Models;

use App\Enums\Gender;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['facility_id', 'name', 'phone', 'dob', 'gender'])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dob' => 'date:Y-m-d',
            'gender' => Gender::class,
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
