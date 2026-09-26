<?php

declare(strict_types=1);

namespace App\Services\Crawl\Exceptions;

use RuntimeException;

/**
 * Raised when a URL must not be fetched. The message is stored on the page row
 * as `crawl_error`, so it names the specific reason rather than "blocked".
 */
final class UnsafeUrlException extends RuntimeException
{
    public static function scheme(string $scheme): self
    {
        return new self("Refusing to fetch [{$scheme}]: only the http and https scheme are allowed.");
    }

    public static function malformed(string $url): self
    {
        return new self("Refusing to fetch a URL with no usable host: [{$url}].");
    }

    public static function credentials(): self
    {
        return new self('Refusing to fetch a URL containing embedded credentials.');
    }

    public static function unresolvable(string $host): self
    {
        return new self("Refusing to fetch [{$host}]: the host does not resolve.");
    }

    public static function blockedAddress(string $host, string $ip): self
    {
        return new self("Refusing to fetch [{$host}]: it resolves to [{$ip}], which is a private, loopback or otherwise reserved address.");
    }
}
