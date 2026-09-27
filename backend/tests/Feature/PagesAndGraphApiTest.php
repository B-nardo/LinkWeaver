<?php

declare(strict_types=1);

use App\Models\Link;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;

/*
|------------------------------------------------------------------------------
| Pages and graph endpoints
|------------------------------------------------------------------------------
|
| The two read surfaces phase 2 adds. Between them they make the app useful
| with no AI involved: which pages nothing points at, and what the link
| structure actually looks like.
|
*/

/**
 * A small site with one orphan, one weakly linked page, one well-linked page,
 * plus navigation links that must not rescue anything.
 *
 * @return array{project: Project, orphan: Page, weak: Page, linked: Page}
 */
function siteWithOrphans(?User $owner = null): array
{
    $project = Project::factory()->for($owner ?? User::factory())->done()->create();

    $make = fn (string $slug): Page => Page::factory()
        ->for($project)
        ->at("https://example.com/{$slug}/")
        ->create(['title' => ucfirst($slug).' page']);

    $orphan = $make('orphan');
    $weak = $make('weak');
    $linked = $make('linked');
    $hubA = $make('hub-a');
    $hubB = $make('hub-b');
    $hubC = $make('hub-c');

    Link::factory()->from($hubA)->to($weak)->create();

    foreach ([$hubA, $hubB, $hubC] as $hub) {
        Link::factory()->from($hub)->to($linked)->create();
    }

    // Every page carries a nav link to the orphan. It stays an orphan.
    foreach ([$hubA, $hubB, $hubC] as $hub) {
        Link::factory()->from($hub)->to($orphan)->navigation()->create();
    }

    return ['project' => $project, 'orphan' => $orphan, 'weak' => $weak, 'linked' => $linked];
}

describe('access control', function (): void {
    // These routes are public so the demo can be read without an account
    // (spec 6). A guest asking for a real project gets 404 rather than 401,
    // which is the stronger answer: it does not confirm the project exists.
    it('hides a real project from a guest', function (string $suffix): void {
        $project = Project::factory()->create();

        $this->getJson("/api/projects/{$project->id}/{$suffix}")->assertNotFound();
    })->with(['pages', 'graph']);

    it('hides another user project behind a 404', function (string $suffix): void {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/projects/{$project->id}/{$suffix}")
            ->assertNotFound();
    })->with(['pages', 'graph']);

    it('lets any signed-in user read the demo project', function (string $suffix): void {
        $project = Project::factory()->demo()->done()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/projects/{$project->id}/{$suffix}")
            ->assertOk();
    })->with(['pages', 'graph']);
});

describe('the pages endpoint', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        $this->site = siteWithOrphans($this->user);
    });

    it('returns every page with its link counts and classification', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/pages")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(6);

        $orphan = collect($response->json('data'))->firstWhere('id', $this->site['orphan']->id);

        expect($orphan['inbound_count'])->toBe(0)
            ->and($orphan['classification'])->toBe('orphan')
            ->and($orphan['classification_label'])->toBe('Orphan')
            ->and($orphan['title'])->toBe('Orphan page');
    });

    it('does not let navigation links rescue a page from orphan status', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/pages?filter=orphan")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        expect($ids)->toContain($this->site['orphan']->id);
    });

    it('filters to weakly linked pages', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/pages?filter=weak")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($this->site['weak']->id);
    });

    it('sorts by inbound links', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/pages?sort=inbound&direction=desc")
            ->assertOk();

        expect($response->json('data.0.id'))->toBe($this->site['linked']->id)
            ->and($response->json('data.0.inbound_count'))->toBe(3);
    });

    it('paginates', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/pages?per_page=2")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and($response->json('meta.total'))->toBe(6);
    });

    it('rejects a filter or sort it does not recognise', function (string $query): void {
        $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/pages?{$query}")
            ->assertStatus(422);
    })->with([
        'filter=nonsense',
        'sort=; DROP TABLE pages',
        'direction=sideways',
        'per_page=5000',
    ]);
});

describe('the graph endpoint', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        $this->site = siteWithOrphans($this->user);
    });

    it('returns a node per page', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/graph")
            ->assertOk();

        expect($response->json('nodes'))->toHaveCount(6);
    });

    it('marks orphan nodes so they can be drawn differently', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/graph")
            ->assertOk();

        $orphan = collect($response->json('nodes'))->firstWhere('id', $this->site['orphan']->id);

        expect($orphan['classification'])->toBe('orphan');
    });

    it('draws an edge only for in-content links', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/graph")
            ->assertOk();

        // Four editorial links; the three navigation links are not structure.
        expect($response->json('edges'))->toHaveCount(4);

        $targets = collect($response->json('edges'))->pluck('target');

        expect($targets)->not->toContain($this->site['orphan']->id);
    });

    it('carries a summary for the overview', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/graph")
            ->assertOk();

        expect($response->json('summary'))->toMatchArray([
            'pages' => 6,
            'orphans' => 4,
            'weak' => 1,
            'linked' => 1,
            'edges' => 4,
            'uncrawled' => 0,
        ]);
    });

    it('reports uncrawled pages so the orphan count can be caveated', function (): void {
        Page::factory()->for($this->site['project'])
            ->at('https://example.com/broken/')
            ->failed()
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->site['project']->id}/graph")
            ->assertOk();

        expect($response->json('summary.uncrawled'))->toBe(1);
    });
});
