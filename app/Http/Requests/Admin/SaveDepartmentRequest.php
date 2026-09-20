<?php

namespace App\Http\Requests\Admin;

use App\Enums\DepartmentType;
use App\Models\Department;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Used for both creating and updating a department.
 */
class SaveDepartmentRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $department = $this->route('department');

        return $department instanceof Department
            ? Gate::inspect('update', $department)
            : true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('departments', 'name')
                    ->where('facility_id', $this->user()->facility_id)
                    ->ignore($this->route('department')?->id),
            ],
            'type' => ['required', Rule::enum(DepartmentType::class)],
            'is_active' => ['boolean'],
            'requires_doctor_assignment' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a department with this name.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'requires_doctor_assignment' => $this->boolean('requires_doctor_assignment'),
        ]);
    }
}
