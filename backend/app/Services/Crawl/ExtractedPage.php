<?php

declare(strict_types=1);

namespace App\Services\Crawl;

/**
 * The useful remains of one crawled HTML document.
 *
 * `contentHash` is a SHA-256 of the extracted text and acts as the cache key for
 * embeddings: a page whose content has not changed never needs re-embedding,
 * which is what keeps the project inside a free Gemini quota.
 */
final readonly class ExtractedPage
{
    /**
     * @param  list<ExtractedLink>  $links
     * @param  list<string>  $headings  Headings in the main content, used by the
     *                                  anchor validator to reject a suggested
     *                                  anchor that is already part of a heading.
     */
    public function __construct(
        public ?string $title,
        public ?string $h1,
        public ?string $metaDescription,
        public string $text,
        public int $wordCount,
        public string $contentHash,
        public array $links,
        public array $headings,
        public bool $usedFallback,
    ) {}

    public static function empty(): self
    {
        return new self(
            title: null,
            h1: null,
            metaDescription: null,
            text: '',
            wordCount: 0,
            contentHash: hash('sha256', ''),
            links: [],
            headings: [],
            usedFallback: false,
        );
    }
}
