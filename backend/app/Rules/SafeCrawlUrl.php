<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Crawl\Exceptions\UnsafeUrlException;
use App\Services\Crawl\SafeUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a sitemap URL the crawler would refuse to fetch.
 *
 * Applying the guard at the request boundary as well as in the job is
 * deliberate: it turns "your project failed three minutes later" into an
 * immediate, explicable 422.
 */
final class SafeCrawlUrl implements ValidationRule
{
    public function __construct(private readonly SafeUrlGuard $guard) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a URL.');

            return;
        }

        try {
            $this->guard->assertSafe($value);
        } catch (UnsafeUrlException $e) {
            $fail($e->getMessage());
        }
    }
}
