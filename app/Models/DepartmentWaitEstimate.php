<?php

namespace App\Models;

use Database\Factories\DepartmentWaitEstimateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How long a department has been measured to take per patient. Written only
 * by the nightly RecalculateDepartmentAverages job.
 */
#[Fillable(['department_id', 'avg_minutes', 'sample_size', 'calculated_at'])]
class DepartmentWaitEstimate extends Model
{
    /** @use HasFactory<DepartmentWaitEstimateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'avg_minutes' => 'float',
            'sample_size' => 'integer',
            'calculated_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
