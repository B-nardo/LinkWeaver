<?php

declare(strict_types=1);

use App\Services\Crawl\Exceptions\UnreadableSitemapException;
use App\Services\Crawl\SitemapParser;
use App\Services\Crawl\UrlNormalizer;

/*
|------------------------------------------------------------------------------
| SitemapParser
|------------------------------------------------------------------------------
|
| Turns one sitemap document into either a list of pages or a list of further
| sitemaps. Recursion and fetching live elsewhere; this class is deliberately
| pure so its many edge cases are cheap to test.
|
*/

function sitemapFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../Fixtures/sitemaps/'.$name);
}

function parser(string $sitemapUrl = 'https://example.com/sitemap.xml'): SitemapParser
{
    return new SitemapParser(UrlNormalizer::forSitemap($sitemapUrl));
}

describe('url sets', function (): void {
    it('recognises a urlset as a list of pages, not sitemaps', function (): void {
        $result = parser()->parse(sitemapFixture('urlset.xml'));

        expect($result->isIndex)->toBeFalse()
            ->and($result->sitemapUrls)->toBe([]);
    });

    it('returns page URLs in normalised form', function (): void {
        $result = parser()->parse(sitemapFixture('urlset.xml'));

        expect($result->pageUrls)->toContain('https://example.com/about/');
    });

    it('deduplicates URLs that normalise to the same page', function (): void {
        $result = parser()->parse(sitemapFixture('urlset.xml'));

        expect(array_count_values($result->pageUrls)['https://example.com/about/'])->toBe(1);
    });

    it('trims whitespace around a location', function (): void {
        expect(parser()->parse(sitemapFixture('urlset.xml'))->pageUrls)
            ->toContain('https://example.com/whitespace/');
    });

    it('skips empty locations', function (): void {
        expect(parser()->parse(sitemapFixture('urlset.xml'))->pageUrls)->not->toContain('');
    });

    it('parses a sitemap that omits the namespace', function (): void {
        expect(parser()->parse(sitemapFixture('no-namespace.xml'))->pageUrls)
            ->toBe(['https://example.com/unnamespaced/']);
    });
});

describe('non-HTML resources', function (): void {
    it('skips URLs that are plainly not pages', function (string $url): void {
        expect(parser()->parse(sitemapFixture('urlset.xml'))->pageUrls)->not->toContain($url);
    })->with([
        'https://example.com/brochure.pdf',
        'https://example.com/logo.png',
        'https://example.com/feed.rss',
    ]);

    it('keeps real pages', function (): void {
        expect(parser()->parse(sitemapFixture('urlset.xml'))->pageUrls)
            ->toContain('https://example.com/services/commercial-insurance/');
    });
});

describe('external URLs', function (): void {
    it('drops URLs belonging to another site', function (): void {
        expect(parser()->parse(sitemapFixture('urlset.xml'))->pageUrls)
            ->not->toContain('https://other.test/external-page/');
    });
});

describe('sitemap indexes', function (): void {
    it('recognises an index and returns child sitemaps rather than pages', function (): void {
        $result = parser()->parse(sitemapFixture('index.xml'));

        expect($result->isIndex)->toBeTrue()
            ->and($result->pageUrls)->toBe([]);
    });

    it('skips taxonomy sitemaps by default', function (string $url): void {
        expect(parser()->parse(sitemapFixture('index.xml'))->sitemapUrls)->not->toContain($url);
    })->with([
        'https://example.com/category-sitemap.xml',
        'https://example.com/post_tag-sitemap.xml',
        'https://example.com/author-sitemap.xml',
    ]);

    it('keeps content sitemaps', function (): void {
        expect(parser()->parse(sitemapFixture('index.xml'))->sitemapUrls)->toBe([
            'https://example.com/post-sitemap.xml',
            'https://example.com/page-sitemap.xml',
        ]);
    });

    it('includes taxonomy sitemaps when the project opts in', function (): void {
        $result = parser()->parse(sitemapFixture('index.xml'), skipTaxonomies: false);

        expect($result->sitemapUrls)->toHaveCount(5);
    });
});

describe('unreadable input', function (): void {
    it('rejects XML it cannot parse', function (): void {
        parser()->parse(sitemapFixture('malformed.xml'));
    })->throws(UnreadableSitemapException::class);

    it('rejects a document that is well-formed but not a sitemap', function (): void {
        parser()->parse(sitemapFixture('not-a-sitemap.xml'));
    })->throws(UnreadableSitemapException::class);

    it('rejects an empty body', function (): void {
        parser()->parse('   ');
    })->throws(UnreadableSitemapException::class);

    // A sitemap is attacker-controlled input that our server fetches, so entity
    // expansion is a file-disclosure vector. The DOCTYPE is stripped before
    // parsing, which leaves the reference undefined and makes the document
    // invalid — rejecting it outright, rather than returning a truncated URL.
    it('refuses a document that declares external entities', function (): void {
        $xxe = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://example.com/&xxe;</loc></url>
            </urlset>
            XML;

        parser()->parse($xxe);
    })->throws(UnreadableSitemapException::class);

    it('never lets file content reach a parsed URL', function (): void {
        $xxe = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://example.com/&xxe;</loc></url>
            </urlset>
            XML;

        try {
            $urls = parser()->parse($xxe)->pageUrls;
        } catch (UnreadableSitemapException) {
            $urls = [];
        }

        expect(implode('', $urls))->not->toContain('root:');
    });
});

describe('gzipped sitemaps', function (): void {
    it('transparently decompresses a gzipped document', function (): void {
        $compressed = gzencode(sitemapFixture('no-namespace.xml'));

        expect(parser()->parse($compressed)->pageUrls)
            ->toBe(['https://example.com/unnamespaced/']);
    });
});
