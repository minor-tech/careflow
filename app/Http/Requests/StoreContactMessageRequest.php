<?php

namespace App\Http\Requests;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactMessageRequest extends FormRequest
{
    /**
     * Anyone may write to us.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'contact' => ['required', 'string', 'max:255', $this->reachable()],
            'facility_name' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            // A field real people never see: only automated form-fillers put anything in it.
            'website' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please tell us your name.',
            'contact.required' => 'Please give an email address or phone number we can reach you on.',
            'message.required' => 'Please write your message.',
            'message.min' => 'Please write a little more, so we can help properly.',
            'message.max' => 'Please keep your message to 5,000 characters or fewer.',
        ];
    }

    /**
     * Either an email address or a phone number, so there is a way to reply.
     */
    private function reachable(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = trim((string) $value);

            $isEmail = str_contains($value, '@') && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

            if (! $isEmail && PhoneNumber::normalize($value) === null) {
                $fail('Enter a valid email address or phone number.');
            }
        };
    }

    /**
     * How the contact detail is kept: a phone number in its standard form, an email as typed.
     */
    public function contactDetail(): string
    {
        $value = trim((string) $this->validated('contact'));

        return str_contains($value, '@') ? $value : PhoneNumber::normalize($value);
    }
}
