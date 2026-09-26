<?php

declare(strict_types=1);

namespace App\Services\Crawl;

/**
 * One internal link found on a page.
 *
 * `inContent` is the field the whole analysis turns on. A link in the main body
 * is an editorial choice about what relates to what; a link in the navigation or
 * footer appears on every page and says nothing. Counting the latter as a real
 * internal link would make every page look well connected and hide every orphan.
 */
final readonly class ExtractedLink
{
    public function __construct(
        public string $url,
        public string $rawHref,
        public string $anchorText,
        public bool $inContent,
    ) {}
}
