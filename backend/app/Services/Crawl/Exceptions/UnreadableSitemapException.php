<?php

declare(strict_types=1);

namespace App\Services\Crawl\Exceptions;

use RuntimeException;

/**
 * Raised when a sitemap cannot be understood. The message is surfaced to the
 * user as the project's `error_message`, so it should explain what was wrong
 * with their sitemap rather than name an internal failure.
 */
final class UnreadableSitemapException extends RuntimeException
{
    public static function empty(): self
    {
        return new self('The sitemap was empty.');
    }

    public static function malformed(string $detail): self
    {
        return new self("The sitemap is not valid XML: {$detail}");
    }

    public static function notASitemap(string $rootElement): self
    {
        return new self("Expected a <urlset> or <sitemapindex> document, found <{$rootElement}>.");
    }
}
