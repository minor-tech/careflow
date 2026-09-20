<?php

namespace App\Http\Requests;

use App\Enums\FeedbackIssue;
use App\Models\Feedback;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicFeedbackRequest extends FormRequest
{
    /**
     * Nobody is signed in: the secret in the visit link is what lets a
     * patient answer for their visit (the controller finds the visit by it).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * What went wrong is only asked of an unhappy patient, so anything sent
     * with a 4 or 5 is dropped before it is looked at, not rejected.
     */
    protected function prepareForValidation(): void
    {
        $rating = $this->input('rating');

        if (is_numeric($rating) && (int) $rating > Feedback::LOW_RATING) {
            $this->merge(['issues' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'issues' => ['nullable', 'array'],
            'issues.*' => ['string', Rule::enum(FeedbackIssue::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Please choose a star rating.',
            'rating.integer' => 'Please choose a star rating.',
            'rating.between' => 'Please choose a star rating.',
            'comment.max' => 'Please keep your comment to 1,000 characters or fewer.',
        ];
    }

    /**
     * A mistake sends the patient back to their own page, not to wherever
     * the browser says they came from.
     */
    protected function getRedirectUrl(): string
    {
        return route('tracking.show', $this->route('token'));
    }
}
