<?php

namespace App\Models;

use Database\Factories\QueueCounterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last queue number handed out on a given day: for one department, or,
 * with no department, the facility-wide registration numbers. Only ever
 * changed through App\Services\QueueNumberGenerator.
 */
#[Fillable(['facility_id', 'department_id', 'date', 'last_number'])]
class QueueCounter extends Model
{
    /** @use HasFactory<QueueCounterFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'last_number' => 'integer',
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
}
