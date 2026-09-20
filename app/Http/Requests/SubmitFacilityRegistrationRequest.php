<?php

namespace App\Http\Requests;

use App\Support\FacilityRegistrationSteps;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitFacilityRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the final screen's own fields are checked here; the controller
     * re-validates the whole draft before committing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return FacilityRegistrationSteps::rules(FacilityRegistrationSteps::LAST);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return FacilityRegistrationSteps::messages();
    }
}
