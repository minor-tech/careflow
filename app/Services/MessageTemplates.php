<?php

namespace App\Services;

use App\Models\Visit;
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
