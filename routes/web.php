<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\FacilityApprovalController;
use App\Http\Controllers\Admin\FacilityController;
use App\Http\Controllers\Admin\PatientNotificationController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\ForcedPasswordChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacilityRegistrationController;
use App\Http\Controllers\FacilityStatusController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\PatientLookupController;
use App\Http\Controllers\PatientVisitController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicFeedbackController;
use App\Http\Controllers\QueueController;
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
});

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
            Route::get('/notifications', [PatientNotificationController::class, 'index'])->name('notifications.index');
            Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
            Route::view('/settings', 'admin.settings')->name('admin.settings');
        });

        // Front desk: register a patient and put them in the queue.
        Route::middleware(['role:admin,receptionist'])->group(function () {
            Route::get('/patients/register', [PatientVisitController::class, 'create'])->name('patients.register');
            Route::post('/patients/register', [PatientVisitController::class, 'store'])->name('patients.register.store');
            Route::get('/patients/lookup', PatientLookupController::class)->middleware('throttle:60,1')->name('patients.lookup');
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
        });
    });
});

require __DIR__.'/auth.php';
