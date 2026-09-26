<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Jobs\ParseSitemapJob;
use App\Models\Link;
use App\Models\Page;
use App\Models\Project;
use App\Services\Crawl\Dns\DnsResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/*
|------------------------------------------------------------------------------
| The crawl pipeline, end to end
|------------------------------------------------------------------------------
|
| Runs the real jobs against a faked network: a sitemap, three pages of real
| WordPress markup, and a robots.txt. Nothing here touches the internet.
|
*/

function fakeSite(array $overrides = []): void
{
    $sitemap = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
          <url><loc>https://example.com/guides/commercial-property/</loc></url>
          <url><loc>https://example.com/services/fleet-insurance/</loc></url>
          <url><loc>https://example.com/services/business-interruption/</loc></url>
        </urlset>
        XML;

    $html = fn (string $file): string => file_get_contents(__DIR__.'/../Fixtures/html/'.$file);

    Http::fake(array_merge([
        'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /wp-admin/", 200),
        'https://example.com/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
        'https://example.com/guides/commercial-property/' => Http::response(
            $html('twentytwentyfour.html'), 200, ['Content-Type' => 'text/html; charset=utf-8']
        ),
        'https://example.com/services/fleet-insurance/' => Http::response(
            $html('astra.html'), 200, ['Content-Type' => 'text/html']
        ),
        'https://example.com/services/business-interruption/' => Http::response(
            $html('generatepress.html'), 200, ['Content-Type' => 'text/html']
        ),
    ], $overrides));
}

beforeEach(function (): void {
    $this->app->bind(DnsResolver::class, fn (): DnsResolver => new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return $host === 'example.com' ? ['93.184.216.34'] : [];
        }
    });

    // The per-domain throttle would release these jobs back to the queue; the
    // politeness behaviour is not what this test is about.
    config()->set('linkweaver.throttle.requests_per_second', 1000);
});

function runPipeline(array $attributes = []): Project
{
    $project = Project::factory()->create(array_merge([
        'sitemap_url' => 'https://example.com/sitemap.xml',
    ], $attributes));

    Bus::dispatch(new ParseSitemapJob($project));

    return $project->refresh();
}

describe('a successful crawl', function (): void {
    beforeEach(function (): void {
        fakeSite();
        $this->project = runPipeline();
    });

    it('creates a page row per sitemap URL', function (): void {
        expect($this->project->pages()->count())->toBe(3)
            ->and($this->project->pages_found)->toBe(3);
    });

    it('finishes in the done state', function (): void {
        expect($this->project->status)->toBe(ProjectStatus::Done);
    });

    it('counts every crawled page', function (): void {
        expect($this->project->pages_crawled)->toBe(3);
    });

    it('stores extracted content', function (): void {
        $page = Page::query()->where('normalized_url', 'https://example.com/guides/commercial-property/')->sole();

        expect($page->title)->toContain('Commercial Property Insurance')
            ->and($page->h1)->toBe('Commercial Property Insurance Explained')
            ->and($page->content_text)->toContain('reinstatement basis')
            ->and($page->word_count)->toBeGreaterThan(50)
            ->and($page->http_status)->toBe(200)
            ->and($page->crawl_error)->toBeNull();
    });

    it('hashes content so embeddings can be cached later', function (): void {
        $page = Page::query()->whereNotNull('content_hash')->first();

        expect($page->content_hash)->toBe(hash('sha256', $page->content_text));
    });

    it('excludes navigation text from the stored content', function (): void {
        $page = Page::query()->where('normalized_url', 'https://example.com/guides/commercial-property/')->sole();

        expect($page->content_text)->not->toContain('Skip to content');
    });

    it('resolves in-content links to the page they point at', function (): void {
        $source = Page::query()->where('normalized_url', 'https://example.com/guides/commercial-property/')->sole();
        $target = Page::query()->where('normalized_url', 'https://example.com/services/business-interruption/')->sole();

        $link = Link::query()
            ->where('source_page_id', $source->id)
            ->where('target_page_id', $target->id)
            ->sole();

        expect($link->in_content)->toBeTrue()
            ->and($link->anchor_text)->toBe('business interruption cover');
    });

    it('records navigation links but never as in-content', function (): void {
        $source = Page::query()->where('normalized_url', 'https://example.com/guides/commercial-property/')->sole();

        $navLink = Link::query()
            ->where('source_page_id', $source->id)
            ->where('target_url', 'https://example.com/privacy/')
            ->sole();

        expect($navLink->in_content)->toBeFalse()
            // The sitemap never listed /privacy/, so there is no page to point at.
            ->and($navLink->target_page_id)->toBeNull();
    });

    it('never stores a link to another site', function (): void {
        expect(Link::query()->where('target_url', 'like', '%other.test%')->count())->toBe(0);
    });
});

describe('partial failure', function (): void {
    it('records a dead page without failing the whole project', function (): void {
        fakeSite([
            'https://example.com/services/fleet-insurance/' => Http::response('Gone', 404),
        ]);

        $project = runPipeline();
        $failed = Page::query()->where('normalized_url', 'https://example.com/services/fleet-insurance/')->sole();

        expect($project->status)->toBe(ProjectStatus::Done)
            ->and($failed->crawl_error)->toContain('404')
            ->and($failed->content_text)->toBeNull()
            // The other two pages still produced a usable audit.
            ->and(Page::query()->whereNotNull('content_text')->count())->toBe(2);
    });

    it('skips a URL that does not return HTML', function (): void {
        fakeSite([
            'https://example.com/services/fleet-insurance/' => Http::response(
                '%PDF-1.4', 200, ['Content-Type' => 'application/pdf']
            ),
        ]);

        runPipeline();
        $skipped = Page::query()->where('normalized_url', 'https://example.com/services/fleet-insurance/')->sole();

        expect($skipped->crawl_error)->toContain('did not return HTML');
    });
});

describe('robots.txt', function (): void {
    it('does not fetch a page the site disallows', function (): void {
        fakeSite([
            'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /services/", 200),
        ]);

        runPipeline();

        $blocked = Page::query()->where('normalized_url', 'https://example.com/services/fleet-insurance/')->sole();
        $allowed = Page::query()->where('normalized_url', 'https://example.com/guides/commercial-property/')->sole();

        expect($blocked->crawl_error)->toContain('robots.txt')
            ->and($allowed->crawl_error)->toBeNull();

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://example.com/services/fleet-insurance/');
    });
});

describe('an unusable sitemap', function (): void {
    it('fails the project with a reason the user can act on', function (): void {
        fakeSite(['https://example.com/sitemap.xml' => Http::response('<html>nope</html>', 200)]);

        $project = runPipeline();

        expect($project->status)->toBe(ProjectStatus::Failed)
            ->and($project->error_message)->not->toBeNull();
    });

    it('fails when the sitemap lists no pages on this domain', function (): void {
        $empty = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
        fakeSite(['https://example.com/sitemap.xml' => Http::response($empty, 200)]);

        $project = runPipeline();

        expect($project->status)->toBe(ProjectStatus::Failed)
            ->and($project->error_message)->toContain('no crawlable pages');
    });
});

describe('sitemap indexes', function (): void {
    it('follows child sitemaps and skips taxonomy archives', function (): void {
        $index = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <sitemap><loc>https://example.com/post-sitemap.xml</loc></sitemap>
              <sitemap><loc>https://example.com/category-sitemap.xml</loc></sitemap>
            </sitemapindex>
            XML;

        $posts = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://example.com/guides/commercial-property/</loc></url>
            </urlset>
            XML;

        fakeSite([
            'https://example.com/sitemap.xml' => Http::response($index, 200),
            'https://example.com/post-sitemap.xml' => Http::response($posts, 200),
        ]);

        $project = runPipeline();

        expect($project->pages_found)->toBe(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'category-sitemap'));
    });
});
