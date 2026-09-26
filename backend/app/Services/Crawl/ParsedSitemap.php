<?php

declare(strict_types=1);

namespace App\Services\Crawl;

/**
 * The result of reading one sitemap document: either a set of pages, or a set
 * of further sitemaps to read. Never both — the sitemap protocol forbids
 * mixing, and treating the two uniformly is how recursion goes wrong.
 */
final readonly class ParsedSitemap
{
    /**
     * @param  list<string>  $sitemapUrls  Child sitemaps, normalised. Empty unless this was an index.
     * @param  list<string>  $pageUrls  Crawlable pages, normalised and deduplicated.
     */
    public function __construct(
        public bool $isIndex,
        public array $sitemapUrls = [],
        public array $pageUrls = [],
    ) {}
}
