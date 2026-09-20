<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use Database\Factories\PatientNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message sent to a patient about their visit, and what became of it. One to
 * someone who was never a patient (a declined queue request) has no patient
 * and carries the number it went to.
 */
#[Fillable(['facility_id', 'patient_id', 'phone', 'visit_id', 'channel', 'message', 'status', 'provider_response', 'sent_at'])]
class PatientNotification extends Model
{
    /** @use HasFactory<PatientNotificationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * The number the message goes to: the patient's, or the one it was addressed to.
     */
    public function recipientPhone(): string
    {
        return $this->phone ?? $this->patient->phone;
    }

    public function markSent(string $providerResponse): void
    {
        $this->update([
            'status' => NotificationStatus::Sent,
            'provider_response' => $providerResponse,
            'sent_at' => now(),
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => NotificationStatus::Failed,
            'provider_response' => $reason,
        ]);
    }
}
