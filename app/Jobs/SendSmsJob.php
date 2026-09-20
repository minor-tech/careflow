<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\PatientNotification;
use App\Services\AfricasTalkingGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hands one recorded message to the SMS gateway and records what happened.
 *
 * This is the only place the gateway is called, and it is queued so a slow or
 * broken gateway can never hold up (or break) a receptionist pressing "Call".
 * It also never throws: even on the sync queue used in tests, a failure ends
 * up as a "failed" row, not an exception in the caller.
 */
class SendSmsJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * A message whose record has been deleted has nothing left to send.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Once only: a retry after a timeout could send the same text twice.
     */
    public int $tries = 1;

    public function __construct(public PatientNotification $notification) {}

    public function handle(AfricasTalkingGateway $gateway): void
    {
        $notification = $this->notification->fresh(['patient', 'facility']);

        // Already dealt with (a re-run, or two workers): never send it twice.
        if ($notification === null || $notification->status !== NotificationStatus::Queued) {
            return;
        }

        try {
            $result = $gateway->send(
                $notification->recipientPhone(),
                $notification->message,
                $notification->facility->sms_sender_id ?: config('services.africastalking.default_sender_id'),
            );
        } catch (Throwable $exception) {
            report($exception);

            $notification->markFailed('Unexpected error while sending: '.$exception->getMessage());

            return;
        }

        $result->accepted ? $notification->markSent($result->response) : $notification->markFailed($result->response);
    }

    /**
     * If the job itself falls over (for instance it times out) the message
     * must not sit as "queued" forever.
     */
    public function failed(?Throwable $exception): void
    {
        $this->notification->fresh()?->markFailed('The sending job failed: '.($exception?->getMessage() ?? 'unknown error'));
    }
}
