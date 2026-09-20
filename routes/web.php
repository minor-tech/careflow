<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\FacilityApprovalController;
use App\Http\Controllers\Admin\FacilityController;
use App\Http\Controllers\Admin\PatientNotificationController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\ForcedPasswordChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DutyController;
use App\Http\Controllers\FacilityRegistrationController;
use App\Http\Controllers\FacilityStatusController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\PatientDoctorController;
use App\Http\Controllers\PatientLookupController;
use App\Http\Controllers\PatientVisitController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicDirectoryController;
use App\Http\Controllers\PublicFeedbackController;
use App\Http\Controllers\PublicTrackController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\RemoteRequestController;
use App\Http\Controllers\RemoteRequestReviewController;
use App\Http\Controllers\SelfCheckinController;
use App\Http\Controllers\TrackingController;
use App\Support\TrackingToken;
use Illuminate\Support\Facades\Route;

// Public
Route::get('/', [GuestController::class, 'home'])->name('home');
Route::get('/about', [GuestController::class, 'about'])->name('about');
Route::get('/contact', [GuestController::class, 'contact'])->name('contact');
Route::post('/contact', [GuestController::class, 'submitContact'])->middleware('throttle:5,1')->name('contact.store');

// A patient's own page for following their visit. No login: the secret in the link is the key.
Route::middleware('throttle:tracking')->prefix('t/{token}')->where(['token' => TrackingToken::PATTERN])->group(function () {
    Route::get('/', [TrackingController::class, 'show'])->name('tracking.show');
    Route::get('/status', [TrackingController::class, 'status'])->name('tracking.status');
    Route::post('/feedback', [PublicFeedbackController::class, 'store'])->name('tracking.feedback.store');
    Route::post('/arrived', [TrackingController::class, 'arrived'])->name('tracking.arrived');
});

// The typed way to the same page, for when scanning doesn't work: queue code plus access PIN, on the facility's own address.
Route::get('/{facility:slug}/track', [PublicTrackController::class, 'showForm'])->where('facility', '[a-z0-9-]+')->name('tracking.entry');
Route::post('/{facility:slug}/track', [PublicTrackController::class, 'attempt'])->where('facility', '[a-z0-9-]+')->name('tracking.entry.attempt');

Route::middleware('guest')->prefix('register-facility')->group(function () {
    Route::get('/', [FacilityRegistrationController::class, 'create'])->name('facility.register');
    Route::post('/', [FacilityRegistrationController::class, 'store'])->name('facility.register.store');

    Route::get('/submitted', [FacilityRegistrationController::class, 'submitted'])->name('facility.register.submitted');

    Route::get('/step/{step}', [FacilityRegistrationController::class, 'show'])
        ->whereIn('step', range(1, 9))
        ->name('facility.register.step');
    Route::post('/step/{step}', [FacilityRegistrationController::class, 'save'])
        ->whereIn('step', range(1, 8))
        ->name('facility.register.save');
});

// Auth required
Route::middleware(['auth'])->group(function () {
    // The one page an admin can reach while the facility is pending review or suspended.
    Route::get('/facility/status', FacilityStatusController::class)
        ->middleware('role:admin')
        ->name('facility.status');

    // Where an account still on its temporary password is held until it sets its own.
    Route::middleware(['facility.active'])->group(function () {
        Route::get('/password/change', [ForcedPasswordChangeController::class, 'create'])->name('password.force');
        Route::post('/password/change', [ForcedPasswordChangeController::class, 'store'])->name('password.force.store');
    });

    // Everything else needs an active facility and a password of the user's own.
    Route::middleware(['facility.active', 'password.changed'])->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard'); // role-based redirect

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::middleware(['role:admin'])->group(function () {
            Route::get('/facility', [FacilityController::class, 'show'])->name('admin.facility');
            Route::resource('staff', StaffController::class)->except('show');
            Route::post('/staff/{staff}/reset-password', [StaffController::class, 'resetPassword'])->whereNumber('staff')->name('staff.reset-password');
            Route::resource('departments', DepartmentController::class)->except('show');
            Route::post('/departments/{department}/services', [ServiceController::class, 'store'])->whereNumber('department')->name('departments.services.store');
            Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->whereNumber('service')->name('services.destroy');
            Route::get('/notifications', [PatientNotificationController::class, 'index'])->name('notifications.index');
            Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
            Route::get('/settings', [SettingsController::class, 'edit'])->name('admin.settings');
            Route::patch('/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
        });

        // Front desk: register a patient and put them in the queue.
        Route::middleware(['role:admin,receptionist'])->group(function () {
            Route::get('/patients/register', [PatientVisitController::class, 'create'])->name('patients.register');
            Route::post('/patients/register', [PatientVisitController::class, 'store'])->name('patients.register.store');
            Route::get('/patients/lookup', PatientLookupController::class)->middleware('throttle:60,1')->name('patients.lookup');
            Route::get('/patients/doctors', PatientDoctorController::class)->middleware('throttle:120,1')->name('patients.doctors');
            // Queue requests from people's phones (from home, or checking themselves in from inside): accept or decline.
            Route::get('/remote-requests', [RemoteRequestReviewController::class, 'index'])->name('remote-requests.index');
            Route::get('/remote-requests/{remoteRequest}', [RemoteRequestReviewController::class, 'show'])->whereNumber('remoteRequest')->name('remote-requests.show');
            Route::post('/remote-requests/{remoteRequest}/accept', [RemoteRequestReviewController::class, 'accept'])->whereNumber('remoteRequest')->name('remote-requests.accept');
            Route::post('/remote-requests/{remoteRequest}/decline', [RemoteRequestReviewController::class, 'decline'])->whereNumber('remoteRequest')->name('remote-requests.decline');

            Route::get('/visits/{visit}/confirmation', [PatientVisitController::class, 'confirmation'])->whereNumber('visit')->name('visits.confirmation');
        });

        // The system admin verifies and approves (or rejects) facility registrations.
        Route::middleware(['role:system_admin'])->prefix('system')->name('system.')->group(function () {
            Route::get('/facilities/pending', [FacilityApprovalController::class, 'index'])->name('facilities.pending');
            Route::get('/facilities/{facility}', [FacilityApprovalController::class, 'show'])->whereNumber('facility')->name('facilities.show');
            Route::post('/facilities/{facility}/approve', [FacilityApprovalController::class, 'approve'])->whereNumber('facility')->name('facilities.approve');
            Route::post('/facilities/{facility}/reject', [FacilityApprovalController::class, 'reject'])->whereNumber('facility')->name('facilities.reject');
        });

        // The working queue: call, start and complete patients in a department.
        Route::middleware(['role:admin,receptionist,doctor,nurse'])->group(function () {
            Route::get('/queue', [QueueController::class, 'index'])->name('queue.index');
            Route::post('/queue/{visit}/call', [QueueController::class, 'call'])->whereNumber('visit')->name('queue.call');
            Route::post('/queue/{visit}/start', [QueueController::class, 'start'])->whereNumber('visit')->name('queue.start');
            Route::post('/queue/{visit}/complete', [QueueController::class, 'complete'])->whereNumber('visit')->name('queue.complete');
            Route::post('/queue/{visit}/cancel', [QueueController::class, 'cancel'])->whereNumber('visit')->name('queue.cancel');
            Route::post('/queue/{visit}/transfer/{department}', [QueueController::class, 'transfer'])->whereNumber(['visit', 'department'])->name('queue.transfer');

            // A patient accepted from home: staff confirm they have come, give them more time, or let the next person go first.
            Route::post('/queue/{visit}/check-in', [QueueController::class, 'checkIn'])->whereNumber('visit')->name('queue.check-in');
            Route::post('/queue/{visit}/wait', [QueueController::class, 'wait'])->whereNumber('visit')->name('queue.wait');
            Route::post('/queue/{visit}/skip', [QueueController::class, 'skip'])->whereNumber('visit')->name('queue.skip');

            // Handing a waiting patient to another doctor: an admin, or the patient's own doctor (the policy decides).
            Route::post('/queue/{visit}/reassign-doctor', [QueueController::class, 'reassignDoctor'])->whereNumber('visit')->name('queue.reassign-doctor');

            // Reception and admins only: a doctor or nurse has no reason to reset a patient's PIN.
            // A doctor switches themself on or off duty; an admin can do it for any doctor (the policy decides).
            Route::post('/staff/{staff}/duty', [DutyController::class, 'update'])->middleware('role:admin,doctor')->whereNumber('staff')->name('staff.duty');

            Route::post('/queue/{visit}/reset-pin', [QueueController::class, 'resetPin'])->middleware('role:admin,receptionist')->whereNumber('visit')->name('queue.reset-pin');
        });
    });
});

require __DIR__.'/auth.php';

// Public, and last: a facility's own address is a single word at the top of the site, so it must never be
// able to shadow a page that already exists. Facilities can't take those words as an address either.
Route::get('/facilities', [PublicDirectoryController::class, 'index'])->name('directory.index');

// The requester's own page for following a request (and, once accepted, on to their tracking page).
Route::middleware('throttle:remote-status')->prefix('r/{code}')->where(['code' => TrackingToken::PATTERN])->group(function () {
    Route::get('/', [RemoteRequestController::class, 'show'])->name('remote.status');
    Route::post('/cancel', [RemoteRequestController::class, 'cancel'])->name('remote.cancel');
});

// Asking for a place from home, and registering yourself from inside the building.
Route::post('/{facility:slug}/request', [RemoteRequestController::class, 'store'])->middleware('throttle:remote-request')->where('facility', '[a-z0-9-]+')->name('remote.request.store');
Route::get('/{facility:slug}/checkin', [SelfCheckinController::class, 'form'])->where('facility', '[a-z0-9-]+')->name('checkin.form');
Route::post('/{facility:slug}/checkin', [SelfCheckinController::class, 'submit'])->middleware('throttle:remote-request')->where('facility', '[a-z0-9-]+')->name('checkin.store');

// A plain slug, looked up by the controller rather than bound here, so an address that is nothing at all is a 404 even when the
// database can't be reached (the error pages must never depend on it). Any other method on such an address is a 404 too, as it always was.
Route::get('/{slug}', [PublicDirectoryController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('directory.show');
Route::match(['POST', 'PUT', 'PATCH', 'DELETE'], '/{slug}', fn () => abort(404))->where('slug', '[a-z0-9-]+');
