<?php

namespace App\Services;

use App\Models\RemoteRequest;
use App\Models\Visit;
use App\Support\ArrivalWindow;
use Illuminate\Support\Str;

/**
 * Builds the text of each SMS from the wording in config/notification_templates.php.
 * Needs the visit's patient, facility and department loaded (call loadMissing).
 */
class MessageTemplates
{
    public function registered(Visit $visit): string
    {
        $link = $this->trackingLink($visit);

        return $link === null
            ? $this->render('registered_without_link', $visit, ['{number}' => (string) $visit->queue_number])
            : $this->render('registered', $visit, ['{number}' => (string) $visit->queue_number, '{link}' => $link]);
    }

    public function called(Visit $visit): string
    {
        return $this->render('called', $visit);
    }

    public function almostTurn(Visit $visit): string
    {
        return $this->render('almost_turn', $visit);
    }

    public function transferred(Visit $visit): string
    {
        return $this->render('transferred', $visit, ['{code}' => $visit->queueLabel()]);
    }

    public function doctorChanged(Visit $visit): string
    {
        return $this->render('doctor_changed', $visit, [
            '{doctor}' => $visit->assignedDoctor?->doctorName() ?? 'another doctor',
            '{code}' => $visit->queueLabel(),
        ]);
    }

    /**
     * Accepted from home: the doctor (if the department gives each patient
     * one), the number in their line, when to arrive and where to follow it.
     * Needs the visit's assigned doctor and facility loaded.
     */
    public function remoteAccepted(Visit $visit, ArrivalWindow $window): string
    {
        $doctor = $visit->isInDoctorQueue() ? $visit->assignedDoctor?->doctorName() : null;

        return $this->render($doctor === null ? 'remote_accepted_without_doctor' : 'remote_accepted', $visit, [
            '{doctor}' => $doctor ?? '',
            '{code}' => $visit->queueLabel(),
            '{window}' => $window->label(),
            '{link}' => $this->trackingLink($visit) ?? '',
        ]);
    }

    /**
     * Not accepted. Says why only if the facility did, briefly.
     */
    public function remoteDeclined(RemoteRequest $request): string
    {
        $reason = filled($request->declined_reason) ? ' '.Str::limit(trim($request->declined_reason), 60, '...') : '';

        return strtr((string) config('notification_templates.remote_declined'), [
            '{facility}' => $request->facility->name,
            '{reason}' => $reason,
        ]);
    }

    public function almostTurnRemote(Visit $visit): string
    {
        return $this->render('almost_turn_remote', $visit);
    }

    public function completed(Visit $visit): string
    {
        return $this->render('completed', $visit);
    }

    /**
     * Where the patient can follow their visit. A visit without a token (none
     * is created without one) gets the wording that has no link in it.
     */
    protected function trackingLink(Visit $visit): ?string
    {
        return $visit->trackingUrl();
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function render(string $template, Visit $visit, array $extra = []): string
    {
        return strtr((string) config("notification_templates.{$template}"), [
            '{facility}' => $visit->facility->name,
            // A first name is short, and is all a message on a lock screen needs.
            '{name}' => Str::before(trim($visit->patient->name), ' '),
            '{department}' => $visit->department?->name ?? 'the service desk',
            ...$extra,
        ]);
    }
}
