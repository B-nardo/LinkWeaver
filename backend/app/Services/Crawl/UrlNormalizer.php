<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use InvalidArgumentException;

/**
 * Reduces a URL to one canonical form so that two references to the same page
 * always compare equal.
 *
 * This is the correctness centrepiece of the crawler. Every comparison — "have
 * we already crawled this?", "does this link point at a page in the project?",
 * "is this page an orphan?" — depends on it. A false negative invents an orphan
 * that does not exist; a false positive silently merges two real pages.
 *
 * An instance is built per project from the sitemap URL, because the correct
 * host form is a per-site fact. A site that publishes `www` URLs should have
 * its bare-host links folded into the `www` form, and the reverse, so that
 * internal links written either way resolve to the same row.
 */
final class UrlNormalizer
{
    /**
     * Parameters that identify a marketing campaign or click, never a resource.
     * Anything prefixed `utm_` is also stripped, handled separately.
     */
    public const array TRACKING_PARAMS = [
        'gclid', 'fbclid', 'msclkid', 'yclid', 'igshid',
        'mc_cid', 'mc_eid', '_ga', '_gl',
    ];

    /**
     * Matches `pages.normalized_url`. Anything longer cannot be stored in the
     * unique index that guarantees one row per canonical URL, so it is rejected
     * here rather than blowing up at insert time.
     */
    public const int MAX_LENGTH = 500;

    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * @param  string  $canonicalHost  Host form the sitemap uses, e.g. `www.example.com`.
     * @param  string  $canonicalScheme  Scheme the sitemap uses, `http` or `https`.
     * @param  list<string>  $trackingParams
     */
    public function __construct(
        private readonly string $canonicalHost,
        private readonly string $canonicalScheme = 'https',
        private readonly array $trackingParams = self::TRACKING_PARAMS,
    ) {}

    public static function forSitemap(string $sitemapUrl): self
    {
        $parts = parse_url(trim($sitemapUrl));

        if ($parts === false || ! isset($parts['host'])) {
            throw new InvalidArgumentException("Cannot derive a canonical host from [{$sitemapUrl}].");
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');

        return new self(
            canonicalHost: strtolower($parts['host']),
            canonicalScheme: isset(self::DEFAULT_PORTS[$scheme]) ? $scheme : 'https',
        );
    }

    /**
     * Returns the canonical form of `$url`, or null when it does not address a
     * crawlable page at all (a `mailto:` link, a bare fragment, a relative URL
     * with no base, or something too long to index).
     */
    public function normalize(string $url, ?string $base = null): ?string
    {
        $absolute = $this->toAbsolute($url, $base);

        if ($absolute === null) {
            return null;
        }

        $parts = parse_url($absolute);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! isset(self::DEFAULT_PORTS[$scheme])) {
            return null;
        }

        // A trailing dot is a legal but redundant way to write a FQDN.
        $host = rtrim(strtolower($parts['host']), '.');

        if ($host === '') {
            return null;
        }

        // Drop the port before the scheme is rewritten, so it is compared
        // against the scheme it was actually written for.
        $port = $parts['port'] ?? null;

        if ($port === self::DEFAULT_PORTS[$scheme]) {
            $port = null;
        }

        // Internal URLs are folded onto the sitemap's own scheme and host form,
        // collapsing http/https and www/non-www duplicates of the same page.
        if ($this->hostIsInternal($host)) {
            $host = $this->canonicalHost;
            $scheme = $this->canonicalScheme;
        }

        $normalized = $scheme.'://'.$host
            .($port !== null ? ':'.$port : '')
            .$this->normalizePath($parts['path'] ?? '/')
            .$this->normalizeQuery($parts['query'] ?? '');

        return strlen($normalized) > self::MAX_LENGTH ? null : $normalized;
    }

    /**
     * Whether the URL points at the project's own site. Unusable input is
     * external rather than an error: callers are iterating over whatever a page
     * happened to put in an href.
     */
    public function isInternal(string $url, ?string $base = null): bool
    {
        $absolute = $this->toAbsolute($url, $base);

        if ($absolute === null) {
            return false;
        }

        $parts = parse_url($absolute);

        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            return false;
        }

        if (! isset(self::DEFAULT_PORTS[strtolower($parts['scheme'])])) {
            return false;
        }

        return $this->hostIsInternal(rtrim(strtolower($parts['host']), '.'));
    }

    /**
     * `www.example.com` and `example.com` are the same site; `sub.example.com`
     * and `example.com.evil.test` are not.
     */
    private function hostIsInternal(string $host): bool
    {
        return $this->withoutWww($host) === $this->withoutWww($this->canonicalHost);
    }

    private function withoutWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Resolves `$url` against `$base` following RFC 3986's reference rules,
     * returning null when the result could not address an http(s) page.
     */
    private function toAbsolute(string $url, ?string $base): ?string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }

        // An explicit scheme means the reference is already absolute. Schemes we
        // cannot crawl (mailto, tel, javascript, data, ftp) are rejected here.
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $matches) === 1) {
            return isset(self::DEFAULT_PORTS[strtolower($matches[1])]) ? $url : null;
        }

        if ($base === null) {
            return null;
        }

        $baseParts = parse_url(trim($base));

        if ($baseParts === false || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $origin = strtolower($baseParts['scheme']).'://'.strtolower($baseParts['host'])
            .(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        // Protocol-relative: inherit only the scheme.
        if (str_starts_with($url, '//')) {
            return strtolower($baseParts['scheme']).':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        // Document-relative: resolve against the base's directory, not the
        // document itself.
        $basePath = $baseParts['path'] ?? '/';
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);

        return $origin.($directory === '' ? '/' : $directory).$url;
    }

    /**
     * Collapses duplicate slashes, resolves `.` and `..`, and applies one
     * consistent trailing-slash rule so a path has exactly one spelling.
     */
    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // Never traverse above the root; a `..` too many is simply spent.
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return '/';
        }

        $resolved = '/'.implode('/', $segments);

        // A final segment carrying an extension is a file, and files do not take
        // a trailing slash. Everything else is a directory-style page, which
        // always does — whichever form the source wrote.
        return $this->looksLikeFile((string) end($segments)) ? $resolved : $resolved.'/';
    }

    private function looksLikeFile(string $segment): bool
    {
        return preg_match('/\.[a-z0-9]{1,8}$/i', $segment) === 1;
    }

    /**
     * Drops tracking parameters and sorts what remains, so that argument order
     * and campaign tags stop producing spurious distinct URLs.
     *
     * Parsed by hand rather than with parse_str, which mangles keys containing
     * dots and silently discards repeated parameters.
     */
    private function normalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $kept = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $key = str_contains($pair, '=') ? substr($pair, 0, (int) strpos($pair, '=')) : $pair;

            if ($this->isTrackingParam($key)) {
                continue;
            }

            $kept[] = $pair;
        }

        if ($kept === []) {
            return '';
        }

        sort($kept, SORT_STRING);

        return '?'.implode('&', $kept);
    }

    private function isTrackingParam(string $key): bool
    {
        $key = strtolower(urldecode($key));

        return str_starts_with($key, 'utm_') || in_array($key, $this->trackingParams, true);
    }
}
