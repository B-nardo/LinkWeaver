<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Fetches and caches robots.txt per host.
 *
 * Cached because a 200-page crawl of one site would otherwise request the same
 * file 200 times, which is exactly the rudeness the file exists to prevent.
 */
final class RobotsTxtRepository
{
    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
        private readonly Cache $cache,
        private readonly string $userAgent,
        private readonly int $cacheTtl,
    ) {}

    public function for(string $url): RobotsTxt
    {
        $origin = $this->originOf($url);

        if ($origin === null) {
            return RobotsTxt::permissive();
        }

        $body = $this->cache->remember(
            "robots:{$this->userAgent}:{$origin}",
            $this->cacheTtl,
            function () use ($origin): string {
                try {
                    $response = $this->fetcher->fetch($origin.'/robots.txt');

                    // Anything other than a served file means "no rules". A 404
                    // is the common case and grants full access by convention.
                    return $response->status === 200 ? $response->body : '';
                } catch (Throwable) {
                    // A site whose robots.txt is unreachable should not fail the
                    // whole crawl, but it also should not be crawled harder than
                    // one that published rules. The throttle still applies.
                    return '';
                }
            }
        );

        return $body === '' ? RobotsTxt::permissive() : RobotsTxt::parse($body, $this->userAgent);
    }

    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
