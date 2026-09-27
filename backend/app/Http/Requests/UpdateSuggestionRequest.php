<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reviewer may only approve or reject. `applied` and `failed` are set by the
 * WordPress integration, and `pending` is where a suggestion starts — letting a
 * request set any of them would let the client rewrite history.
 */
final class UpdateSuggestionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['approved', 'rejected'])],
        ];
    }
}
