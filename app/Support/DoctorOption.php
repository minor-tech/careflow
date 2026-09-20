<?php

namespace App\Support;

use App\Models\User;

/**
 * One doctor as a receptionist is shown them when choosing who a patient goes
 * to: how long their line is, and roughly how long a patient joining it would
 * wait. Staff information only: the patient's own page never shows a doctor's
 * workload.
 */
final readonly class DoctorOption
{
    /**
     * @param  int  $activeCount  Patients in this doctor's line today who are waiting, called or being seen.
     * @param  bool  $recommended  The shortest line among the doctors who fit.
     */
    public function __construct(
        public User $doctor,
        public int $activeCount,
        public WaitEstimate $estimate,
        public bool $recommended,
    ) {}

    /**
     * @return array{id: int, name: string, specialty: string|null, waiting: int, estimate: string, recommended: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->doctor->id,
            'name' => $this->doctor->doctorName(),
            'specialty' => $this->doctor->service?->name,
            'waiting' => $this->activeCount,
            'estimate' => $this->activeCount === 0 ? 'No wait' : $this->estimate->label(),
            'recommended' => $this->recommended,
        ];
    }
}
