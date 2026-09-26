<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Services\Crawl\Exceptions\UnreadableSitemapException;
use Throwable;

/**
 * Walks a sitemap, following `<sitemapindex>` references, and returns the pages.
 *
 * Bounded in three directions, because the document is attacker-controlled: a
 * depth limit stops a self-referencing index recursing forever, a visited set
 * stops two indexes pointing at each other, and the page cap keeps the project
 * inside what the free-tier analysis stage can hold in memory.
 */
final class SitemapCollector
{
    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
        private readonly SitemapParser $parser,
        private readonly int $maxDepth,
        private readonly int $maxPages,
    ) {}

    /**
     * @return list<string>
     *
     * @throws UnreadableSitemapException when the root sitemap itself is unusable
     */
    public function collect(string $sitemapUrl, bool $skipTaxonomies = true): array
    {
        /** @var array<string, true> $pages */
        $pages = [];
        /** @var array<string, true> $visited */
        $visited = [];

        $queue = [[$sitemapUrl, 0]];
        $isRoot = true;

        while ($queue !== []) {
            [$url, $depth] = array_shift($queue);

            if (isset($visited[$url]) || $depth > $this->maxDepth) {
                continue;
            }

            $visited[$url] = true;

            try {
                $parsed = $this->parser->parse($this->fetcher->fetch($url)->body, $skipTaxonomies);
            } catch (Throwable $e) {
                // The root sitemap failing means the project cannot start, and
                // the user needs to know why. A child sitemap failing is worth
                // skipping over: the rest of the site is still auditable.
                if ($isRoot) {
                    throw $e instanceof UnreadableSitemapException
                        ? $e
                        : UnreadableSitemapException::malformed($e->getMessage());
                }

                continue;
            } finally {
                $isRoot = false;
            }

            if ($parsed->isIndex) {
                foreach ($parsed->sitemapUrls as $child) {
                    $queue[] = [$child, $depth + 1];
                }

                continue;
            }

            foreach ($parsed->pageUrls as $page) {
                if (count($pages) >= $this->maxPages) {
                    return array_keys($pages);
                }

                $pages[$page] = true;
            }
        }

        return array_keys($pages);
    }
}
