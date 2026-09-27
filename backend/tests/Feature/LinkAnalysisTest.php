<?php

declare(strict_types=1);

use App\Enums\PageClassification;
use App\Models\Link;
use App\Models\Page;
use App\Models\Project;
use App\Services\Analysis\PageLinkQuery;

/*
|------------------------------------------------------------------------------
| PageLinkQuery
|------------------------------------------------------------------------------
|
| Counting inbound links sounds trivial and is not. Three rules decide whether
| the product tells the truth:
|
|   - navigation and footer links appear on every page and must never count;
|   - a page cannot rescue itself from orphanhood by linking to itself;
|   - a link whose target was never crawled points at no page at all.
|
| Each is a way to silently under-report orphans, which is the one failure that
| would make the whole tool worthless.
|
*/

function analyzer(int $weakThreshold = 2): PageLinkQuery
{
    return new PageLinkQuery($weakThreshold);
}

function pageNamed(Project $project, string $slug): Page
{
    return Page::factory()->for($project)->at("https://example.com/{$slug}/")->create();
}

/**
 * @return array<string, int> slug => inbound count
 */
function inboundBySlug(Project $project): array
{
    return analyzer()->for($project)->get()
        ->mapWithKeys(fn (Page $page): array => [
            trim((string) parse_url($page->normalized_url, PHP_URL_PATH), '/') => (int) $page->inbound_count,
        ])
        ->all();
}

describe('what counts as an inbound link', function (): void {
    it('counts an in-content link from another page', function (): void {
        $project = Project::factory()->create();
        $source = pageNamed($project, 'source');
        $target = pageNamed($project, 'target');

        Link::factory()->from($source)->to($target)->create();

        expect(inboundBySlug($project)['target'])->toBe(1);
    });

    it('ignores a navigation link, however many pages carry it', function (): void {
        $project = Project::factory()->create();
        $target = pageNamed($project, 'target');

        foreach (['a', 'b', 'c'] as $slug) {
            Link::factory()->from(pageNamed($project, $slug))->to($target)->navigation()->create();
        }

        expect(inboundBySlug($project)['target'])->toBe(0);
    });

    it('ignores a page linking to itself', function (): void {
        $project = Project::factory()->create();
        $page = pageNamed($project, 'lonely');

        Link::factory()->from($page)->to($page)->create();

        expect(inboundBySlug($project)['lonely'])->toBe(0);
    });

    it('ignores a link whose target was never crawled', function (): void {
        $project = Project::factory()->create();
        $source = pageNamed($project, 'source');
        pageNamed($project, 'target');

        Link::factory()->from($source)->unresolved()->create();

        expect(inboundBySlug($project)['target'])->toBe(0);
    });

    it('does not count links belonging to another project', function (): void {
        $project = Project::factory()->create();
        $target = pageNamed($project, 'target');

        $other = Project::factory()->create();
        Link::factory()->from(pageNamed($other, 'elsewhere'))
            ->create(['target_page_id' => $target->id]);

        expect(inboundBySlug($project)['target'])->toBe(0);
    });

    it('counts several distinct sources separately', function (): void {
        $project = Project::factory()->create();
        $target = pageNamed($project, 'target');

        foreach (['a', 'b', 'c'] as $slug) {
            Link::factory()->from(pageNamed($project, $slug))->to($target)->create();
        }

        expect(inboundBySlug($project)['target'])->toBe(3);
    });
});

describe('classification', function (): void {
    it('classifies each page by its inbound count', function (int $inbound, PageClassification $expected): void {
        $project = Project::factory()->create();
        $target = pageNamed($project, 'target');

        for ($i = 0; $i < $inbound; $i++) {
            Link::factory()->from(pageNamed($project, "s{$i}"))->to($target)->create();
        }

        $page = analyzer()->for($project)->where('pages.id', $target->id)->sole();

        expect(PageClassification::fromInboundCount((int) $page->inbound_count, 2))->toBe($expected);
    })->with([
        'no links' => [0, PageClassification::Orphan],
        'one link' => [1, PageClassification::Weak],
        'two links' => [2, PageClassification::Weak],
        'three links' => [3, PageClassification::Linked],
    ]);
});

describe('outbound counts', function (): void {
    it('counts only resolved in-content links leaving a page', function (): void {
        $project = Project::factory()->create();
        $source = pageNamed($project, 'source');

        Link::factory()->from($source)->to(pageNamed($project, 'a'))->create();
        Link::factory()->from($source)->to(pageNamed($project, 'b'))->create();
        Link::factory()->from($source)->to(pageNamed($project, 'c'))->navigation()->create();
        Link::factory()->from($source)->unresolved()->create();

        $page = analyzer()->for($project)->where('pages.id', $source->id)->sole();

        expect((int) $page->outbound_count)->toBe(2);
    });
});

describe('filtering and sorting', function (): void {
    beforeEach(function (): void {
        $this->project = Project::factory()->create();
        $this->orphan = pageNamed($this->project, 'orphan');
        $this->weak = pageNamed($this->project, 'weak');
        $this->linked = pageNamed($this->project, 'linked');

        Link::factory()->from(pageNamed($this->project, 'h1'))->to($this->weak)->create();

        foreach (['h2', 'h3', 'h4'] as $slug) {
            Link::factory()->from(pageNamed($this->project, $slug))->to($this->linked)->create();
        }
    });

    it('returns only orphans when asked', function (): void {
        $ids = analyzer()->filtered($this->project, 'orphan')->pluck('pages.id');

        expect($ids)->toContain($this->orphan->id)
            ->not->toContain($this->weak->id)
            ->not->toContain($this->linked->id);
    });

    it('returns only weakly linked pages when asked', function (): void {
        $ids = analyzer()->filtered($this->project, 'weak')->pluck('pages.id');

        expect($ids)->toContain($this->weak->id)
            ->not->toContain($this->orphan->id)
            ->not->toContain($this->linked->id);
    });

    it('returns everything by default', function (): void {
        expect(analyzer()->filtered($this->project, null)->count())->toBe(7);
    });

    it('sorts by inbound links, most linked first', function (): void {
        $counts = analyzer()->filtered($this->project, null, 'inbound', 'desc')
            ->get()->pluck('inbound_count')->map(fn ($count): int => (int) $count)->all();

        expect($counts)->toBe([3, 1, 0, 0, 0, 0, 0]);
    });

    it('sorts ascending so the least linked pages surface first', function (): void {
        $counts = analyzer()->filtered($this->project, null, 'inbound', 'asc')
            ->get()->pluck('inbound_count')->map(fn ($count): int => (int) $count)->all();

        expect($counts)->toBe([0, 0, 0, 0, 0, 1, 3]);
    });
});

describe('summary', function (): void {
    it('counts pages by classification for the overview', function (): void {
        $project = Project::factory()->create();
        $weak = pageNamed($project, 'weak');
        pageNamed($project, 'orphan');

        Link::factory()->from(pageNamed($project, 'source'))->to($weak)->create();

        expect(analyzer()->summary($project))->toMatchArray([
            'pages' => 3,
            'orphans' => 2,
            'weak' => 1,
            'linked' => 0,
            'edges' => 1,
        ]);
    });

    it('reports pages that failed to crawl, because they make orphans uncertain', function (): void {
        $project = Project::factory()->create();
        pageNamed($project, 'fine');
        Page::factory()->for($project)->at('https://example.com/broken/')->failed()->create();

        expect(analyzer()->summary($project)['uncrawled'])->toBe(1);
    });
});
