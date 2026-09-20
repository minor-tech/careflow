<?php

namespace App\Http\Requests;

use App\Support\FacilityRegistrationSteps;
use App\Support\RegistrationDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveRegistrationStepRequest extends FormRequest
{
    /**
     * A screen can only be submitted once every screen before it is done.
     */
    public function authorize(): bool
    {
        return app(RegistrationDraft::class)->canOpen($this->step());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $draft = app(RegistrationDraft::class);

        return FacilityRegistrationSteps::rules($this->step(), [...$draft->data(), ...$this->all()]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return FacilityRegistrationSteps::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return FacilityRegistrationSteps::attributes();
    }

    public function step(): int
    {
        return (int) $this->route('step');
    }

    protected function prepareForValidation(): void
    {
        match ($this->step()) {
            4 => $this->prepareOperations(),
            5 => $this->prepareAdmin(),
            FacilityRegistrationSteps::STAFF_STEP => $this->prepareStaff(),
            default => null,
        };
    }

    private function prepareOperations(): void
    {
        $isTwentyFourHour = $this->boolean('is_24hr');

        $this->merge([
            'is_24hr' => $isTwentyFourHour,
            'opens_at' => $isTwentyFourHour ? null : $this->input('opens_at'),
            'closes_at' => $isTwentyFourHour ? null : $this->input('closes_at'),
        ]);
    }

    private function prepareAdmin(): void
    {
        $this->merge([
            'two_factor_enabled' => $this->boolean('two_factor_enabled'),
            'admin_email' => strtolower(trim((string) $this->input('admin_email'))),
        ]);
    }

    /**
     * Drop untouched rows so a blank "add another" row never blocks the
     * screen; "Skip for now" discards whatever was typed.
     */
    private function prepareStaff(): void
    {
        $rows = $this->boolean('skip') ? [] : collect((array) $this->input('staff', []))
            ->map(fn (mixed $row): array => [
                ...(array) $row,
                'email' => strtolower(trim((string) (((array) $row)['email'] ?? ''))),
            ])
            ->filter(fn (array $row): bool => filled($row['name'] ?? null)
                || filled($row['email'])
                || filled($row['phone'] ?? null))
            ->values()
            ->all();

        $this->merge(['staff' => $rows]);
    }
}
