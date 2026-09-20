<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Models\ContactMessage;
use App\Notifications\ContactMessageReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Throwable;

/**
 * The public front door: the pages anyone can read before they have an
 * account. Nothing here touches a facility's data.
 */
class GuestController extends Controller
{
    public function home(): View
    {
        return view('site.home');
    }

    public function about(): View
    {
        return view('site.about');
    }

    public function contact(): View
    {
        return view('site.contact');
    }

    public function submitContact(StoreContactMessageRequest $request): RedirectResponse
    {
        $thanks = redirect()->route('contact')->with('status', "Thanks, we've got your message and will get back to you.");

        // Filled in only by a bot: look as if it worked, and keep nothing.
        if (filled($request->input('website'))) {
            return $thanks;
        }

        $message = ContactMessage::create([
            'name' => $request->validated('name'),
            'contact' => $request->contactDetail(),
            'facility_name' => $request->validated('facility_name'),
            'message' => $request->validated('message'),
        ]);

        $this->tellTheTeam($message);

        return $thanks;
    }

    /**
     * Email the support address if one is set. The message is already saved,
     * so a mail failure is reported but never shown to the person writing.
     */
    private function tellTheTeam(ContactMessage $message): void
    {
        $address = config('careflow.support_email');

        if (blank($address)) {
            return;
        }

        try {
            Notification::route('mail', $address)->notify(new ContactMessageReceived($message));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
