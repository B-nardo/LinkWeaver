<?php

declare(strict_types=1);

use App\Services\Crawl\RobotsTxt;

/*
|------------------------------------------------------------------------------
| RobotsTxt
|------------------------------------------------------------------------------
|
| Being a well-behaved crawler is not optional for a tool that asks strangers
| for permission to crawl their site. Parsing is kept separate from fetching so
| the rule precedence — the part that is actually easy to get wrong — is unit
| testable.
|
*/

function robots(string $body, string $agent = 'LinkweaverBot'): RobotsTxt
{
    return RobotsTxt::parse($body, $agent);
}

describe('group selection', function (): void {
    it('applies the wildcard group when no named group matches', function (): void {
        $rules = robots("User-agent: *\nDisallow: /private/");

        expect($rules->allows('/private/page/'))->toBeFalse()
            ->and($rules->allows('/public/'))->toBeTrue();
    });

    it('prefers a group naming our agent over the wildcard', function (): void {
        $rules = robots("User-agent: *\nDisallow: /\n\nUser-agent: LinkweaverBot\nDisallow: /admin/");

        expect($rules->allows('/blog/'))->toBeTrue()
            ->and($rules->allows('/admin/'))->toBeFalse();
    });

    it('matches the agent case-insensitively', function (): void {
        $rules = robots("User-agent: linkweaverbot\nDisallow: /nope/");

        expect($rules->allows('/nope/'))->toBeFalse();
    });

    it('honours a group listing several agents', function (): void {
        $rules = robots("User-agent: SomeBot\nUser-agent: LinkweaverBot\nDisallow: /shared/");

        expect($rules->allows('/shared/'))->toBeFalse();
    });
});

describe('rule precedence', function (): void {
    it('lets a longer Allow override a shorter Disallow', function (): void {
        $rules = robots("User-agent: *\nDisallow: /blog/\nAllow: /blog/public/");

        expect($rules->allows('/blog/public/post/'))->toBeTrue()
            ->and($rules->allows('/blog/private/'))->toBeFalse();
    });

    it('keeps the Disallow when it is the longer match', function (): void {
        $rules = robots("User-agent: *\nAllow: /files/\nDisallow: /files/secret/");

        expect($rules->allows('/files/secret/x/'))->toBeFalse()
            ->and($rules->allows('/files/open/'))->toBeTrue();
    });

    it('treats an empty Disallow as permitting everything', function (): void {
        expect(robots("User-agent: *\nDisallow:")->allows('/anything/'))->toBeTrue();
    });

    it('blocks the whole site for Disallow: /', function (): void {
        expect(robots("User-agent: *\nDisallow: /")->allows('/'))->toBeFalse();
    });
});

describe('wildcards', function (): void {
    it('supports * inside a path', function (): void {
        $rules = robots("User-agent: *\nDisallow: /*.pdf");

        expect($rules->allows('/files/report.pdf'))->toBeFalse()
            ->and($rules->allows('/files/report.html'))->toBeTrue();
    });

    it('supports $ as an end anchor', function (): void {
        $rules = robots("User-agent: *\nDisallow: /page$");

        expect($rules->allows('/page'))->toBeFalse()
            ->and($rules->allows('/page/sub/'))->toBeTrue();
    });
});

describe('crawl delay', function (): void {
    it('reads a crawl delay from the matching group', function (): void {
        expect(robots("User-agent: *\nCrawl-delay: 3")->crawlDelay())->toBe(3.0);
    });

    it('returns null when no delay is declared', function (): void {
        expect(robots("User-agent: *\nDisallow: /x/")->crawlDelay())->toBeNull();
    });
});

describe('tolerance', function (): void {
    it('permits everything when robots.txt is empty or absent', function (string $body): void {
        expect(robots($body)->allows('/anything/'))->toBeTrue();
    })->with(['', '   ', "\n\n"]);

    it('ignores comments and unknown directives', function (): void {
        $rules = robots("# a comment\nSitemap: https://example.com/sitemap.xml\nUser-agent: *\nDisallow: /x/ # trailing");

        expect($rules->allows('/x/'))->toBeFalse()
            ->and($rules->allows('/y/'))->toBeTrue();
    });

    it('compares only the path portion of a URL', function (): void {
        $rules = robots("User-agent: *\nDisallow: /private/");

        expect($rules->allows('https://example.com/private/page/'))->toBeFalse();
    });
});
