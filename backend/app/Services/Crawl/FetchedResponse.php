<?php

declare(strict_types=1);

namespace App\Services\Crawl;

/**
 * The outcome of one safe HTTP fetch.
 *
 * `finalUrl` is kept separately from the requested URL because redirects are
 * common and the page's real identity is where it ended up, not where we asked.
 */
final readonly class FetchedResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public string $finalUrl,
        public ?string $contentType,
        public bool $truncated = false,
    ) {}

    public function isHtml(): bool
    {
        if ($this->contentType === null) {
            return true;
        }

        $type = strtolower($this->contentType);

        return str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
    }
}
