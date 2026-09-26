<?php

declare(strict_types=1);

use App\Services\Crawl\ContentExtractor;
use App\Services\Crawl\UrlNormalizer;

/*
|------------------------------------------------------------------------------
| ContentExtractor
|------------------------------------------------------------------------------
|
| Decides what counts as "the page" and what is furniture. This matters twice
| over: the text feeds the embeddings, so navigation copy would make every page
| look alike, and the links decide the graph, so counting a site-wide footer
| link as an internal link would make every page look well connected and hide
| every orphan.
|
| Fixtures mirror the markup of real WordPress themes: Twenty Twenty-Four block
| output, Astra, GeneratePress, and one legacy theme with no semantic wrapper at
| all to force the readability fallback.
|
*/

function htmlFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../Fixtures/html/'.$name);
}

function extractor(string $sitemapUrl = 'https://example.com/sitemap.xml'): ContentExtractor
{
    return new ContentExtractor(UrlNormalizer::forSitemap($sitemapUrl));
}

function extractFixture(string $name, string $url = 'https://example.com/guides/page/'): object
{
    return extractor()->extract(htmlFixture($name), $url);
}

describe('metadata', function (): void {
    it('reads the document title', function (): void {
        expect(extractFixture('twentytwentyfour.html')->title)
            ->toBe('Commercial Property Insurance Explained | Example Brokers');
    });

    it('reads the first h1 separately from the title', function (): void {
        expect(extractFixture('twentytwentyfour.html')->h1)
            ->toBe('Commercial Property Insurance Explained');
    });

    it('reads the meta description', function (): void {
        expect(extractFixture('twentytwentyfour.html')->metaDescription)
            ->toBe('What commercial property insurance covers, who needs it, and how limits are set.');
    });

    it('leaves a missing meta description null rather than inventing one', function (): void {
        expect(extractFixture('generatepress.html')->metaDescription)->toBeNull();
    });
});

describe('main content selection', function (): void {
    it('extracts the article body', function (): void {
        expect(extractFixture('twentytwentyfour.html')->text)
            ->toContain('Commercial property insurance protects the buildings');
    });

    it('works across theme conventions', function (string $file, string $expected): void {
        expect(extractFixture($file)->text)->toContain($expected);
    })->with([
        ['twentytwentyfour.html', 'reinstatement basis'],
        ['astra.html', 'one renewal date'],
        ['generatepress.html', 'indemnity period'],
        ['no-semantic-wrapper.html', 'compulsory for almost every business'],
    ]);

    it('falls back to readability when no known wrapper holds the content', function (): void {
        expect(extractFixture('no-semantic-wrapper.html')->usedFallback)->toBeTrue();
    });

    it('does not use the fallback when a known wrapper is present', function (): void {
        expect(extractFixture('astra.html')->usedFallback)->toBeFalse();
    });
});

describe('furniture removal', function (): void {
    it('excludes navigation, headers and footers from the text', function (string $file, string $furniture): void {
        expect(extractFixture($file)->text)->not->toContain($furniture);
    })->with([
        ['twentytwentyfour.html', 'Skip to content'],
        ['twentytwentyfour.html', 'Related reading'],
        ['astra.html', 'You may also like'],
        ['astra.html', 'Get a quote'],
        ['generatepress.html', 'Newsletter'],
        ['generatepress.html', 'Log in to comment'],
    ]);

    it('excludes script and style contents', function (): void {
        expect(extractFixture('twentytwentyfour.html')->text)
            ->not->toContain('dataLayer')
            ->not->toContain('color:red');
    });
});

describe('word count and hashing', function (): void {
    it('counts words in the extracted text', function (): void {
        expect(extractFixture('astra.html')->wordCount)->toBeGreaterThan(40);
    });

    it('hashes the extracted text with sha256', function (): void {
        $page = extractFixture('astra.html');

        expect($page->contentHash)->toBe(hash('sha256', $page->text));
    });

    it('produces the same hash for the same content, enabling the embedding cache', function (): void {
        expect(extractFixture('astra.html')->contentHash)
            ->toBe(extractFixture('astra.html')->contentHash);
    });

    it('produces a different hash when the content differs', function (): void {
        expect(extractFixture('astra.html')->contentHash)
            ->not->toBe(extractFixture('generatepress.html')->contentHash);
    });
});

describe('link extraction', function (): void {
    it('marks links inside the main content as in-content', function (): void {
        $links = extractFixture('twentytwentyfour.html')->links;
        $contentLinks = array_values(array_filter($links, fn ($l) => $l->inContent));
        $urls = array_map(fn ($l) => $l->url, $contentLinks);

        expect($urls)->toContain('https://example.com/services/business-interruption/');
    });

    it('marks navigation and footer links as not in-content', function (string $url): void {
        $links = extractFixture('twentytwentyfour.html')->links;
        $match = array_values(array_filter($links, fn ($l) => $l->url === $url));

        expect($match)->not->toBeEmpty()
            ->and($match[0]->inContent)->toBeFalse();
    })->with([
        'https://example.com/services/',
        'https://example.com/privacy/',
    ]);

    it('never counts a related-posts widget as in-content', function (): void {
        $links = extractFixture('twentytwentyfour.html')->links;
        $match = array_values(array_filter($links, fn ($l) => $l->url === 'https://example.com/blog/cyber-cover/'));

        expect($match[0]->inContent)->toBeFalse();
    });

    it('resolves relative hrefs against the page URL', function (): void {
        $urls = array_map(fn ($l) => $l->url, extractFixture('astra.html')->links);

        expect($urls)->toContain('https://example.com/services/commercial-property/');
    });

    it('normalises absolute internal links to the canonical form', function (): void {
        $urls = array_map(fn ($l) => $l->url, extractFixture('twentytwentyfour.html')->links);

        expect($urls)->toContain('https://example.com/services/fleet-insurance/');
    });

    it('captures the anchor text', function (): void {
        $links = extractFixture('twentytwentyfour.html')->links;
        $match = array_values(array_filter(
            $links,
            fn ($l) => $l->url === 'https://example.com/services/business-interruption/'
        ));

        expect($match[0]->anchorText)->toBe('business interruption cover');
    });

    it('discards links to other sites, which cannot be internal link targets', function (): void {
        $urls = array_map(fn ($l) => $l->url, extractFixture('twentytwentyfour.html')->links);

        expect($urls)->not->toContain('https://other.test/regulator');
    });

    it('discards non-page schemes', function (): void {
        $urls = array_map(fn ($l) => $l->url, extractFixture('twentytwentyfour.html')->links);

        expect(implode(' ', $urls))
            ->not->toContain('mailto')
            ->not->toContain('tel:');
    });

    it('deduplicates repeated links to the same target', function (): void {
        $html = '<html><body><main><p>'
            .'<a href="/a/">one</a><a href="/a/">two</a>'
            .'</p></main></body></html>';

        $links = extractor()->extract($html, 'https://example.com/x/')->links;

        expect($links)->toHaveCount(1);
    });
});

describe('degenerate input', function (): void {
    it('returns an empty page rather than throwing on empty HTML', function (): void {
        $page = extractor()->extract('', 'https://example.com/x/');

        expect($page->text)->toBe('')
            ->and($page->wordCount)->toBe(0)
            ->and($page->links)->toBe([]);
    });

    it('survives HTML with no head or body', function (): void {
        $page = extractor()->extract('<p>Just a fragment</p>', 'https://example.com/x/');

        expect($page->title)->toBeNull()
            ->and($page->text)->toContain('Just a fragment');
    });
});
