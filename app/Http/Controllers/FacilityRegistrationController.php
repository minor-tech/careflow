<?php

namespace App\Http\Controllers;

use App\Actions\RegisterFacility;
use App\Enums\DataRole;
use App\Enums\DepartmentType;
use App\Enums\FacilityType;
use App\Enums\NotificationChannel;
use App\Enums\OperatingDay;
use App\Enums\OwnershipType;
use App\Enums\UserRole;
use App\Http\Requests\SaveRegistrationStepRequest;
use App\Http\Requests\SubmitFacilityRegistrationRequest;
use App\Support\Counties;
use App\Support\FacilityRegistrationSteps;
use App\Support\RegistrationDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class FacilityRegistrationController extends Controller
{
    public function __construct(private RegistrationDraft $draft) {}

    /**
     * Open the wizard where the applicant left off.
     */
    public function create(): RedirectResponse
    {
        return redirect()->route('facility.register.step', $this->draft->resumeStep());
    }

    public function show(int $step): View|RedirectResponse
    {
        if (! $this->draft->canOpen($step)) {
            return redirect()->route('facility.register.step', $this->draft->resumeStep());
        }

        $data = $this->draft->data();
        $definition = FacilityRegistrationSteps::all()[$step];

        return view('facility-registration.steps.'.$definition['slug'], [
            'step' => $step,
            'definition' => $definition,
            'steps' => FacilityRegistrationSteps::all(),
            'completed' => $this->draft->completedSteps(),
            'hasPassword' => $this->draft->hasPassword(),
            'value' => fn (string $key, mixed $default = null): mixed => old($key, $data[$key] ?? $default),
            'options' => [
                'counties' => array_combine(Counties::all(), Counties::all()),
                'facilityTypes' => FacilityType::options(),
                'ownershipTypes' => OwnershipType::options(),
                'days' => OperatingDay::options(),
                'departmentTypes' => DepartmentType::options(),
                'staffRoles' => UserRole::staffOptions(),
                'channels' => NotificationChannel::options(),
                'dataRoles' => DataRole::options(),
            ],
        ]);
    }

    public function save(SaveRegistrationStepRequest $request, int $step): RedirectResponse
    {
        $validated = $request->validated();

        if ($step === FacilityRegistrationSteps::STAFF_STEP) {
            $validated['staff'] ??= [];
        }

        $this->draft->save($step, $validated);

        return redirect()->route('facility.register.step', $step + 1);
    }

    /**
     * Commit the whole registration. Screens 1-4 and 8-9 are hard
     * requirements; the draft is re-validated in full and any failure sends
     * the applicant back to the earliest screen that needs attention.
     */
    public function store(SubmitFacilityRegistrationRequest $request, RegisterFacility $registerFacility): RedirectResponse
    {
        $data = [...$this->draft->data(), ...$request->validated()];

        $validator = Validator::make(
            $data,
            FacilityRegistrationSteps::commitRules($data),
            FacilityRegistrationSteps::messages(),
            FacilityRegistrationSteps::attributes(),
        );

        if ($validator->fails()) {
            $step = min(array_map(
                FacilityRegistrationSteps::stepForField(...),
                $validator->errors()->keys(),
            ));

            return redirect()
                ->route('facility.register.step', min($step, $this->draft->resumeStep()))
                ->withErrors($validator);
        }

        $facility = $registerFacility->handle($validator->validated());

        $this->draft->clear();

        return redirect()
            ->route('facility.register.submitted')
            ->with('registered_facility', $facility->name);
    }

    public function submitted(): View
    {
        return view('facility-registration.submitted', [
            'facilityName' => session('registered_facility'),
        ]);
    }
}
