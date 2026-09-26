<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\SafeCrawlUrl;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'sitemap_url' => [
                'required',
                'string',
                'max:2048',
                // The guard runs here as well as in the job so an unreachable or
                // blocked URL is a 422 now, not a failed project in three minutes.
                app(SafeCrawlUrl::class),
            ],
            'skip_taxonomies' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sitemap_url.required' => 'Enter the URL of the sitemap you want to audit.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sitemap_url'))) {
            $this->merge(['sitemap_url' => trim($this->string('sitemap_url')->toString())]);
        }
    }
}
