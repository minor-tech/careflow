<?php

namespace App\Actions;

use App\Enums\RemoteQueueAvailability;
use App\Enums\RemoteRequestSource;
use App\Enums\VisitStatus;
use App\Exceptions\RemoteRequestRefused;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\RemoteRequest;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

class SubmitRemoteRequest
{
    /**
     * How many self check-ins can be waiting for staff at once. They have no
     * daily limit or cut-off (the person is already in the building), so this
     * only stops a script flooding the review screen.
     */
    public const MAX_PENDING_SELF_CHECKINS = 50;

    /**
     * Record a request to join today's queue: from home, or from inside the
     * building on the person's own phone.
     *
     * Requests from home are refused when the facility isn't taking them (off,
     * past today's cut-off, or its limit of waiting requests is reached), and
     * both kinds are refused for a phone number that already has a request
     * waiting or a place in today's queue. The refusal says so without saying
     * anything about the other request, so it can't be used to look up who has
     * one.
     *
     * @param  array{name: string, phone: string, service_id?: int|string|null, requested_arrival?: string|null, preferred_doctor_id?: int|string|null}  $data  Validated; phone already canonical.
     *
     * @throws RemoteRequestRefused
     */
    public function handle(Facility $facility, array $data, RemoteRequestSource $source): RemoteRequest
    {
        // Checked and inserted under one lock on the facility, so two people taking the last place can't both get it.
        return DB::transaction(function () use ($facility, $data, $source): RemoteRequest {
            $facility = Facility::whereKey($facility->id)->lockForUpdate()->firstOrFail();

            $this->refuseIfNotTaking($facility, $source);
            $this->refuseIfAlreadyHasAPlace($facility, $data['phone']);

            return $facility->remoteRequests()->create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'service_id' => $data['service_id'] ?? null,
                'preferred_doctor_id' => $facility->remote_queue_allow_doctor_choice ? ($data['preferred_doctor_id'] ?? null) : null,
                'source' => $source,
                // Someone already here isn't asking for a future time.
                'requested_arrival' => $source === RemoteRequestSource::SelfCheckin
                    ? now(config('careflow.timezone'))->format('H:i:s')
                    : $data['requested_arrival'].':00',
            ]);
        });
    }

    private function refuseIfNotTaking(Facility $facility, RemoteRequestSource $source): void
    {
        if ($source === RemoteRequestSource::SelfCheckin) {
            if (! $facility->self_checkin_enabled) {
                throw RemoteRequestRefused::notOffered();
            }

            $waiting = $facility->remoteRequests()->where('source', RemoteRequestSource::SelfCheckin)->pending()->madeToday()->count();

            if ($waiting >= self::MAX_PENDING_SELF_CHECKINS) {
                throw RemoteRequestRefused::selfCheckinFull();
            }

            return;
        }

        $availability = $facility->remoteQueueAvailability();

        if ($availability === RemoteQueueAvailability::Off) {
            throw RemoteRequestRefused::notOffered();
        }

        if (! $availability->isOpen()) {
            throw RemoteRequestRefused::unavailable($availability);
        }
    }

    private function refuseIfAlreadyHasAPlace(Facility $facility, string $phone): void
    {
        $waiting = $facility->remoteRequests()->where('phone', $phone)->pending()->madeToday()->exists();

        if ($waiting) {
            throw RemoteRequestRefused::alreadyWaiting();
        }

        $inQueue = Visit::query()
            ->where('facility_id', $facility->id)
            ->registeredToday()
            ->whereNotIn('status', [VisitStatus::Completed, VisitStatus::Cancelled])
            ->whereIn('patient_id', Patient::where('facility_id', $facility->id)->where('phone', $phone)->select('id'))
            ->exists();

        if ($inQueue) {
            throw RemoteRequestRefused::alreadyInQueue();
        }
    }
}
