<?php

declare(strict_types=1);

use App\Services\Crawl\UrlNormalizer;

/*
|------------------------------------------------------------------------------
| UrlNormalizer
|------------------------------------------------------------------------------
|
| Every URL comparison in the application runs through this class, so its edge
| cases are the application's edge cases. A false negative invents an orphan
| page; a false positive silently merges two real pages into one row.
|
| The normalizer is built per project from the sitemap URL, because "the right
| form of the host" is a per-site fact: a site that publishes www URLs should
| have its non-www links folded into the www form, and vice versa.
|
*/

function normalizer(string $sitemapUrl = 'https://example.com/sitemap.xml'): UrlNormalizer
{
    return UrlNormalizer::forSitemap($sitemapUrl);
}

describe('scheme and host', function (): void {
    it('lowercases the scheme and host but preserves path case', function (): void {
        expect(normalizer()->normalize('HTTPS://EXAMPLE.COM/About-Us/'))
            ->toBe('https://example.com/About-Us/');
    });

    it('strips the default port for the scheme', function (): void {
        expect(normalizer()->normalize('https://example.com:443/about/'))
            ->toBe('https://example.com/about/');

        expect(normalizer('http://example.com/sitemap.xml')->normalize('http://example.com:80/about/'))
            ->toBe('http://example.com/about/');
    });

    it('keeps a non-default port, which identifies a genuinely different origin', function (): void {
        expect(normalizer()->normalize('https://example.com:8443/about/'))
            ->toBe('https://example.com:8443/about/');
    });

    it('folds internal http URLs onto the sitemap scheme so one page is one row', function (): void {
        expect(normalizer()->normalize('http://example.com/about/'))
            ->toBe('https://example.com/about/');
    });

    it('leaves an external URL scheme alone', function (): void {
        expect(normalizer()->normalize('http://other.test/page/'))
            ->toBe('http://other.test/page/');
    });
});

describe('www handling', function (): void {
    it('adds www when the sitemap uses www', function (): void {
        expect(normalizer('https://www.example.com/sitemap.xml')->normalize('https://example.com/about/'))
            ->toBe('https://www.example.com/about/');
    });

    it('removes www when the sitemap does not use www', function (): void {
        expect(normalizer()->normalize('https://www.example.com/about/'))
            ->toBe('https://example.com/about/');
    });

    it('does not rewrite the www form of an external host', function (): void {
        expect(normalizer()->normalize('https://www.other.test/page/'))
            ->toBe('https://www.other.test/page/');
    });
});

describe('trailing slash', function (): void {
    it('adds a trailing slash to an extensionless path', function (): void {
        expect(normalizer()->normalize('https://example.com/about'))
            ->toBe('https://example.com/about/');
    });

    it('leaves an existing trailing slash alone', function (): void {
        expect(normalizer()->normalize('https://example.com/about/'))
            ->toBe('https://example.com/about/');
    });

    it('does not add a trailing slash to a file path', function (): void {
        expect(normalizer()->normalize('https://example.com/report.pdf'))
            ->toBe('https://example.com/report.pdf');
    });

    it('removes a trailing slash from a file path', function (): void {
        expect(normalizer()->normalize('https://example.com/page.html/'))
            ->toBe('https://example.com/page.html');
    });

    it('normalises the bare root to a single slash', function (): void {
        expect(normalizer()->normalize('https://example.com'))->toBe('https://example.com/');
        expect(normalizer()->normalize('https://example.com/'))->toBe('https://example.com/');
    });

    it('collapses duplicate slashes in the path', function (): void {
        expect(normalizer()->normalize('https://example.com//blog///post/'))
            ->toBe('https://example.com/blog/post/');
    });
});

describe('query strings', function (): void {
    it('strips utm parameters', function (): void {
        expect(normalizer()->normalize('https://example.com/about/?utm_source=x&utm_medium=y&utm_campaign=z'))
            ->toBe('https://example.com/about/');
    });

    it('strips click identifiers', function (): void {
        expect(normalizer()->normalize('https://example.com/about/?gclid=abc&fbclid=def'))
            ->toBe('https://example.com/about/');
    });

    it('keeps meaningful parameters', function (): void {
        expect(normalizer()->normalize('https://example.com/search/?q=insurance'))
            ->toBe('https://example.com/search/?q=insurance');
    });

    it('sorts remaining parameters so argument order stops mattering', function (): void {
        expect(normalizer()->normalize('https://example.com/search/?page=2&q=insurance'))
            ->toBe(normalizer()->normalize('https://example.com/search/?q=insurance&page=2'));
    });

    it('drops a tracking parameter but keeps the rest', function (): void {
        expect(normalizer()->normalize('https://example.com/search/?utm_source=news&q=insurance'))
            ->toBe('https://example.com/search/?q=insurance');
    });

    it('removes a dangling question mark', function (): void {
        expect(normalizer()->normalize('https://example.com/about/?'))
            ->toBe('https://example.com/about/');
    });
});

describe('fragments', function (): void {
    it('strips the fragment', function (): void {
        expect(normalizer()->normalize('https://example.com/about/#team'))
            ->toBe('https://example.com/about/');
    });

    it('treats a fragment-only link as not a page', function (): void {
        expect(normalizer()->normalize('#section', 'https://example.com/about/'))->toBeNull();
    });
});

describe('relative resolution', function (): void {
    it('resolves a root-relative path against the base', function (): void {
        expect(normalizer()->normalize('/contact/', 'https://example.com/blog/post/'))
            ->toBe('https://example.com/contact/');
    });

    it('resolves a document-relative path against the base directory', function (): void {
        expect(normalizer()->normalize('sibling/', 'https://example.com/blog/post/'))
            ->toBe('https://example.com/blog/post/sibling/');
    });

    it('resolves parent traversal', function (): void {
        expect(normalizer()->normalize('../contact/', 'https://example.com/blog/post/'))
            ->toBe('https://example.com/blog/contact/');
    });

    it('does not traverse above the root', function (): void {
        expect(normalizer()->normalize('../../../../etc/', 'https://example.com/a/b/'))
            ->toBe('https://example.com/etc/');
    });

    it('resolves a protocol-relative URL using the base scheme', function (): void {
        expect(normalizer()->normalize('//cdn.other.test/asset/', 'https://example.com/'))
            ->toBe('https://cdn.other.test/asset/');
    });

    it('returns null for a relative URL with no base to resolve against', function (): void {
        expect(normalizer()->normalize('/contact/'))->toBeNull();
    });
});

describe('unusable input', function (): void {
    it('rejects non-http schemes', function (string $url): void {
        expect(normalizer()->normalize($url))->toBeNull();
    })->with([
        'mailto:hello@example.com',
        'tel:+441234567890',
        'javascript:void(0)',
        'ftp://example.com/file.txt',
        'data:text/html,<h1>hi</h1>',
    ]);

    it('rejects empty and whitespace-only input', function (string $url): void {
        expect(normalizer()->normalize($url))->toBeNull();
    })->with(['', '   ', "\n\t"]);

    it('rejects a URL longer than the indexed column allows', function (): void {
        $long = 'https://example.com/'.str_repeat('a', 600).'/';

        expect(normalizer()->normalize($long))->toBeNull();
    });

    it('trims surrounding whitespace rather than rejecting the URL', function (): void {
        expect(normalizer()->normalize("  https://example.com/about/\n"))
            ->toBe('https://example.com/about/');
    });
});

describe('internal detection', function (): void {
    it('treats both www and bare forms of the site as internal', function (string $url): void {
        expect(normalizer()->isInternal($url))->toBeTrue();
    })->with([
        'https://example.com/about/',
        'https://www.example.com/about/',
        'http://example.com/about/',
        'HTTPS://EXAMPLE.COM/about/',
    ]);

    it('treats other hosts as external', function (string $url): void {
        expect(normalizer()->isInternal($url))->toBeFalse();
    })->with([
        'https://other.test/page/',
        'https://notexample.com/page/',
        'https://example.com.evil.test/page/',
        'https://sub.example.com/page/',
    ]);

    it('resolves a relative link against the base before deciding', function (): void {
        expect(normalizer()->isInternal('/contact/', 'https://example.com/blog/'))->toBeTrue();
    });

    it('treats unusable input as external rather than throwing', function (): void {
        expect(normalizer()->isInternal('mailto:hello@example.com'))->toBeFalse();
    });
});

describe('idempotence', function (): void {
    it('returns the same result when applied twice', function (string $url): void {
        $normalizer = normalizer();
        $once = $normalizer->normalize($url);

        expect($normalizer->normalize((string) $once))->toBe($once);
    })->with([
        'https://EXAMPLE.com/About?utm_source=x#frag',
        'http://www.example.com//blog//post',
        'https://example.com',
        'https://example.com/page.html/',
    ]);
});
