<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;

/**
 * Keeps the crawler polite: at most N requests per second to any one host.
 *
 * Applied as job middleware rather than inside the fetcher so a throttled job is
 * released back to the queue instead of sleeping on a worker. With a single
 * worker that distinction is small; with several it is the difference between
 * queueing politely and hammering a stranger's server in parallel.
 */
final class ThrottlePerDomain
{
    public function __construct(
        private readonly string $host,
        private readonly int $requestsPerSecond,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $limiter = app(RateLimiter::class);
        $key = 'crawl-domain:'.$this->host;

        if ($limiter->tooManyAttempts($key, max(1, $this->requestsPerSecond))) {
            // Release rather than fail: the page is fine, we simply arrived too
            // soon. availableIn() avoids a tight retry loop.
            $job->release(max(1, $limiter->availableIn($key)));

            return;
        }

        $limiter->hit($key, 1);

        $next($job);
    }
}
