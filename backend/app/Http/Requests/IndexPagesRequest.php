<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters and sorting for the pages table.
 *
 * Every value is an allow-list rather than a pass-through: `sort` and
 * `direction` end up in an ORDER BY clause, so an unvalidated value is an
 * injection point, and `per_page` is bounded so one request cannot ask the
 * database for the whole table.
 */
final class IndexPagesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', Rule::in(['orphan', 'weak', 'attention', 'linked'])],
            'sort' => ['nullable', Rule::in(['inbound', 'outbound', 'title', 'words', 'url'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filter(): ?string
    {
        $filter = $this->query('filter');

        return is_string($filter) && $filter !== '' ? $filter : null;
    }

    public function sort(): string
    {
        $sort = $this->query('sort');

        return is_string($sort) && $sort !== '' ? $sort : 'inbound';
    }

    /**
     * Ascending by default: the least-linked pages are the ones worth looking
     * at, so they belong at the top without the user asking.
     */
    public function direction(): string
    {
        $direction = $this->query('direction');

        return $direction === 'desc' ? 'desc' : 'asc';
    }

    public function perPage(): int
    {
        return (int) ($this->query('per_page') ?? 25);
    }
}
