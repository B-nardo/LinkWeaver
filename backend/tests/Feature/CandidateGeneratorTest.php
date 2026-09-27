<?php

declare(strict_types=1);

use App\Models\Embedding;
use App\Models\Link;
use App\Models\Page;
use App\Models\Project;
use App\Models\Suggestion;
use App\Services\Analysis\CandidateGenerator;

/*
|------------------------------------------------------------------------------
| CandidateGenerator
|------------------------------------------------------------------------------
|
| Spec 5.6: for each page, the top N most similar pages above a threshold,
| excluding pairs where the source already links to the target.
|
| That exclusion is the one that makes the output useful. Without it the first
| suggestion for every page would be the page it already links to most
| obviously, and the tool would spend its credibility telling people to do what
| they have already done.
|
*/

function generator(
    float $threshold = 0.75,
    int $perPage = 5,
    float $similarityWeight = 0.6,
    float $scarcityWeight = 0.4,
): CandidateGenerator {
    return new CandidateGenerator(
        model: 'gemini-embedding-2',
        threshold: $threshold,
        candidatesPerPage: $perPage,
        similarityWeight: $similarityWeight,
        scarcityWeight: $scarcityWeight,
    );
}

/**
 * Creates a page carrying an embedding built from the given vector.
 *
 * @param  list<float>  $vector
 */
function embeddedPage(Project $project, string $slug, array $vector): Page
{
    $page = Page::factory()->for($project)->at("https://example.com/{$slug}/")->create([
        'title' => ucfirst($slug),
    ]);

    Embedding::factory()->forPage($page)->withVector($vector)->create();

    return $page;
}

describe('which pairs become candidates', function (): void {
    it('suggests a related page that is not already linked', function (): void {
        $project = Project::factory()->create();
        $source = embeddedPage($project, 'source', [1.0, 0.0]);
        $target = embeddedPage($project, 'target', [0.99, 0.14]);

        generator()->generate($project);

        expect(Suggestion::query()
            ->where('source_page_id', $source->id)
            ->where('target_page_id', $target->id)
            ->exists())->toBeTrue();
    });

    it('never suggests a link the source already has', function (): void {
        $project = Project::factory()->create();
        $source = embeddedPage($project, 'source', [1.0, 0.0]);
        $target = embeddedPage($project, 'target', [0.99, 0.14]);

        Link::factory()->from($source)->to($target)->create();

        generator()->generate($project);

        // Only this direction is ruled out. The reverse is still an
        // opportunity, which the next test covers.
        expect(Suggestion::query()
            ->where('source_page_id', $source->id)
            ->where('target_page_id', $target->id)
            ->exists())->toBeFalse();
    });

    it('still suggests the reverse direction when only one way is linked', function (): void {
        $project = Project::factory()->create();
        $source = embeddedPage($project, 'source', [1.0, 0.0]);
        $target = embeddedPage($project, 'target', [0.99, 0.14]);

        Link::factory()->from($source)->to($target)->create();

        generator()->generate($project);

        expect(Suggestion::query()
            ->where('source_page_id', $target->id)
            ->where('target_page_id', $source->id)
            ->exists())->toBeTrue();
    });

    it('ignores a navigation link when deciding what is already linked', function (): void {
        $project = Project::factory()->create();
        $source = embeddedPage($project, 'source', [1.0, 0.0]);
        $target = embeddedPage($project, 'target', [0.99, 0.14]);

        // A footer link is not an editorial link, so the opportunity stands.
        Link::factory()->from($source)->to($target)->navigation()->create();

        generator()->generate($project);

        expect(Suggestion::query()
            ->where('source_page_id', $source->id)
            ->where('target_page_id', $target->id)
            ->exists())->toBeTrue();
    });

    it('never suggests a page links to itself', function (): void {
        $project = Project::factory()->create();
        embeddedPage($project, 'only', [1.0, 0.0]);

        generator()->generate($project);

        expect(Suggestion::query()->count())->toBe(0);
    });

    it('drops pairs below the similarity threshold', function (): void {
        $project = Project::factory()->create();
        embeddedPage($project, 'source', [1.0, 0.0]);
        embeddedPage($project, 'unrelated', [0.0, 1.0]);

        generator(threshold: 0.75)->generate($project);

        expect(Suggestion::query()->count())->toBe(0);
    });

    it('does not reach across projects', function (): void {
        $project = Project::factory()->create();
        embeddedPage($project, 'source', [1.0, 0.0]);

        $other = Project::factory()->create();
        embeddedPage($other, 'elsewhere', [1.0, 0.0]);

        generator()->generate($project);

        expect(Suggestion::query()->count())->toBe(0);
    });

    it('ignores pages that have no embedding yet', function (): void {
        $project = Project::factory()->create();
        embeddedPage($project, 'source', [1.0, 0.0]);
        Page::factory()->for($project)->at('https://example.com/unembedded/')->create();

        generator()->generate($project);

        expect(Suggestion::query()->count())->toBe(0);
    });

    it('ignores embeddings produced by a different model', function (): void {
        $project = Project::factory()->create();
        $source = embeddedPage($project, 'source', [1.0, 0.0]);
        $stale = Page::factory()->for($project)->at('https://example.com/stale/')->create();

        Embedding::factory()->forPage($stale)->withVector([1.0, 0.0])
            ->create(['model' => 'some-older-model']);

        generator()->generate($project);

        expect(Suggestion::query()->count())->toBe(0)
            ->and($source->id)->not->toBeNull();
    });
});

describe('how many', function (): void {
    it('keeps only the top N per source page', function (): void {
        $project = Project::factory()->create();
        embeddedPage($project, 'source', [1.0, 0.0]);

        foreach (range(1, 6) as $i) {
            embeddedPage($project, "related-{$i}", [1.0, 0.01 * $i]);
        }

        generator(perPage: 2)->generate($project);

        $source = Page::query()->where('normalized_url', 'https://example.com/source/')->sole();

        expect(Suggestion::query()->where('source_page_id', $source->id)->count())->toBe(2);
    });

    it('keeps the most similar ones', function (): void {
        $project = Project::factory()->create();
        $source = embeddedPage($project, 'source', [1.0, 0.0]);
        $close = embeddedPage($project, 'close', [1.0, 0.02]);
        embeddedPage($project, 'further', [1.0, 0.5]);

        generator(perPage: 1)->generate($project);

        expect(Suggestion::query()->where('source_page_id', $source->id)->sole()->target_page_id)
            ->toBe($close->id);
    });
});

describe('scoring', function (): void {
    it('records the similarity it measured', function (): void {
        $project = Project::factory()->create();
        embeddedPage($project, 'source', [1.0, 0.0]);
        embeddedPage($project, 'target', [1.0, 0.0]);

        generator()->generate($project);

        expect(Suggestion::query()->first()->similarity)->toEqualWithDelta(1.0, 1e-6);
    });

    it('ranks a suggestion pointing at an orphan above one pointing at a popular page', function (): void {
        $project = Project::factory()->create();
        $source = embeddedPage($project, 'source', [1.0, 0.0]);
        $orphan = embeddedPage($project, 'orphan', [1.0, 0.15]);
        $popular = embeddedPage($project, 'popular', [1.0, 0.01]);

        // Give the popular page a healthy set of inbound editorial links.
        foreach (range(1, 6) as $i) {
            $hub = embeddedPage($project, "hub-{$i}", [0.0, 1.0]);
            Link::factory()->from($hub)->to($popular)->create();
        }

        generator(perPage: 10)->generate($project);

        $toOrphan = Suggestion::query()
            ->where('source_page_id', $source->id)->where('target_page_id', $orphan->id)->sole();
        $toPopular = Suggestion::query()
            ->where('source_page_id', $source->id)->where('target_page_id', $popular->id)->sole();

        // The popular page is the closer match, and still ranks lower.
        expect($toPopular->similarity)->toBeGreaterThan($toOrphan->similarity)
            ->and($toOrphan->priority_score)->toBeGreaterThan($toPopular->priority_score);
    });

    it('leaves the anchor empty for phase 4 to fill', function (): void {
        $project = Project::factory()->create();
        embeddedPage($project, 'source', [1.0, 0.0]);
        embeddedPage($project, 'target', [1.0, 0.0]);

        generator()->generate($project);

        $suggestion = Suggestion::query()->first();

        expect($suggestion->anchor_text)->toBeNull()
            ->and($suggestion->context_sentence)->toBeNull()
            ->and($suggestion->status->value)->toBe('pending');
    });
});

describe('re-running', function (): void {
    it('does not duplicate candidates when run twice', function (): void {
        $project = Project::factory()->create();
        embeddedPage($project, 'source', [1.0, 0.0]);
        embeddedPage($project, 'target', [1.0, 0.0]);

        generator()->generate($project);
        $first = Suggestion::query()->count();

        generator()->generate($project);

        expect(Suggestion::query()->count())->toBe($first);
    });

    it('reports how many candidates it produced', function (): void {
        $project = Project::factory()->create();
        embeddedPage($project, 'source', [1.0, 0.0]);
        embeddedPage($project, 'target', [1.0, 0.0]);

        expect(generator()->generate($project))->toBe(2);
    });
});
