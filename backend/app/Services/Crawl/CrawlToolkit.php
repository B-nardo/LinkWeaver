<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\Project;
use App\Services\Crawl\Dns\DnsResolver;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Builds the crawl collaborators for one project.
 *
 * They cannot simply be container singletons: UrlNormalizer, SitemapParser and
 * ContentExtractor are all configured from the project's own sitemap URL, since
 * the canonical host form is a per-site fact. This keeps that wiring in one
 * place instead of repeating it in every job.
 */
final class CrawlToolkit
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly DnsResolver $dns,
        private readonly Cache $cache,
    ) {}

    public function normalizer(Project $project): UrlNormalizer
    {
        return UrlNormalizer::forSitemap($project->sitemap_url);
    }

    public function parser(Project $project): SitemapParser
    {
        return new SitemapParser(
            $this->normalizer($project),
            config('linkweaver.sitemap.skip_extensions'),
            config('linkweaver.sitemap.taxonomy_patterns'),
        );
    }

    public function extractor(Project $project): ContentExtractor
    {
        return new ContentExtractor(
            $this->normalizer($project),
            config('linkweaver.extraction.content_selectors'),
            config('linkweaver.extraction.strip_selectors'),
            (int) config('linkweaver.extraction.min_content_words'),
        );
    }

    public function fetcher(): SafeHttpFetcher
    {
        return new SafeHttpFetcher(
            $this->http,
            new SafeUrlGuard($this->dns),
            (string) config('linkweaver.http.user_agent'),
            (int) config('linkweaver.http.connect_timeout'),
            (int) config('linkweaver.http.timeout'),
            (int) config('linkweaver.http.max_bytes'),
            (int) config('linkweaver.http.max_redirects'),
        );
    }

    public function robots(): RobotsTxtRepository
    {
        return new RobotsTxtRepository(
            $this->fetcher(),
            $this->cache,
            (string) config('linkweaver.http.user_agent'),
            (int) config('linkweaver.robots.cache_ttl'),
        );
    }

    public function collector(Project $project): SitemapCollector
    {
        return new SitemapCollector(
            $this->fetcher(),
            $this->parser($project),
            (int) config('linkweaver.sitemap.max_depth'),
            (int) config('linkweaver.max_pages'),
        );
    }
}
