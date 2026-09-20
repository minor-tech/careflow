<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Models\PatientNotification;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin's log of messages sent to patients, mainly so a delivery problem
 * can be found without digging in the database.
 */
class PatientNotificationController extends Controller
{
    /**
     * A message still "queued" after this long means the queue worker probably isn't running.
     */
    private const STUCK_AFTER_MINUTES = 5;

    public function index(Request $request): View
    {
        $facility = $this->facility($request);
        $status = NotificationStatus::tryFrom((string) $request->query('status'));

        $notifications = $facility->patientNotifications()
            ->with('patient:id,name,phone')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $counts = PatientNotification::query()
            ->where('facility_id', $facility->id)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('notifications.index', [
            'notifications' => $notifications,
            'status' => $status,
            'counts' => [
                'all' => (int) $counts->sum(),
                'failed' => (int) $counts->get('failed', 0),
                'queued' => (int) $counts->get('queued', 0),
                'sent' => (int) $counts->get('sent', 0),
            ],
            'looksStuck' => $facility->patientNotifications()
                ->where('status', NotificationStatus::Queued)
                ->where('created_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
                ->exists(),
        ]);
    }
}
