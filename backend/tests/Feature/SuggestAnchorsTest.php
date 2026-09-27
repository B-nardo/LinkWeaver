<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Enums\SuggestionStatus;
use App\Jobs\SuggestAnchorsJob;
use App\Models\Link;
use App\Models\Page;
use App\Models\Project;
use App\Models\Suggestion;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|------------------------------------------------------------------------------
| SuggestAnchorsJob
|------------------------------------------------------------------------------
|
| Spec 11: no test may touch the real Gemini API. Every response here is faked.
|
| The behaviour under test is mostly refusal: what the job does when the model
| returns something that cannot be used. Storing one unusable anchor is worse
| than storing none, because it looks exactly like a good suggestion until
| somebody tries to apply it.
|
*/

const SOURCE_BODY = 'Commercial property insurance protects the buildings and contents a '
    .'business depends on. Setting the sum insured too low triggers the average clause, so a '
    .'formal valuation every three years is worth the cost.';

beforeEach(function (): void {
    config()->set('linkweaver.gemini.api_key', 'test-key');
    config()->set('linkweaver.gemini.text_model', 'gemini-3.5-flash-lite');
    config()->set('linkweaver.anchors.per_project', 100);

    // The job paces its own requests; tests should not actually wait.
    Sleep::fake();
});

function fakeAnchor(mixed $payload, string $finishReason = 'STOP'): void
{
    Http::fake([
        '*generateContent' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => is_string($payload) ? $payload : json_encode($payload)]]],
                'finishReason' => $finishReason,
            ]],
        ]),
    ]);
}

/**
 * A project with one candidate suggestion awaiting an anchor.
 *
 * @return array{project: Project, suggestion: Suggestion, source: Page, target: Page}
 */
function candidateProject(array $sourceOverrides = []): array
{
    $project = Project::factory()->create(['status' => ProjectStatus::Analyzing]);

    $source = Page::factory()->for($project)->at('https://example.com/property/')->create(array_merge([
        'title' => 'Commercial Property Insurance',
        'content_text' => SOURCE_BODY,
        'headings' => [],
    ], $sourceOverrides));

    $target = Page::factory()->for($project)->at('https://example.com/underinsurance/')->create([
        'title' => 'The Underinsurance Trap',
        'meta_description' => 'Why sums insured matter.',
    ]);

    $suggestion = Suggestion::query()->create([
        'project_id' => $project->id,
        'source_page_id' => $source->id,
        'target_page_id' => $target->id,
        'similarity' => 0.87,
        'priority_score' => 0.9,
        'status' => SuggestionStatus::Pending,
    ]);

    return compact('project', 'suggestion', 'source', 'target');
}

describe('a usable anchor', function (): void {
    it('stores the anchor and its sentence', function (): void {
        fakeAnchor([
            'anchor' => 'triggers the average clause',
            'sentence' => 'Setting the sum insured too low triggers the average clause, so a formal valuation every three years is worth the cost.',
        ]);

        $fixture = candidateProject();
        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        $suggestion = $fixture['suggestion']->refresh();

        expect($suggestion->anchor_text)->toBe('triggers the average clause')
            ->and($suggestion->context_sentence)->toContain('triggers the average clause')
            ->and($suggestion->status)->toBe(SuggestionStatus::Pending);
    });

    it('sends the target title and source content to the model', function (): void {
        fakeAnchor(['anchor' => 'triggers the average clause']);

        Bus::dispatch(new SuggestAnchorsJob(candidateProject()['project']));

        Http::assertSent(function (Request $request): bool {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'];

            return str_contains($prompt, 'The Underinsurance Trap')
                && str_contains($prompt, 'triggers the average clause');
        });
    });

    it('asks for JSON with a schema', function (): void {
        fakeAnchor(['anchor' => 'triggers the average clause']);

        Bus::dispatch(new SuggestAnchorsJob(candidateProject()['project']));

        Http::assertSent(fn (Request $request): bool => $request->data()['generationConfig']['responseMimeType'] === 'application/json'
            && isset($request->data()['generationConfig']['responseSchema']));
    });
});

describe('refusing unusable output', function (): void {
    it('rejects a paraphrase the model invented', function (): void {
        fakeAnchor(['anchor' => 'sets off the averaging clause']);

        $fixture = candidateProject();
        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        $suggestion = $fixture['suggestion']->refresh();

        expect($suggestion->anchor_text)->toBeNull()
            ->and($suggestion->status)->toBe(SuggestionStatus::Failed);
    });

    it('rejects an anchor of the wrong length', function (string $anchor): void {
        fakeAnchor(['anchor' => $anchor]);

        $fixture = candidateProject();
        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        expect($fixture['suggestion']->refresh()->anchor_text)->toBeNull();
    })->with([
        'too short' => 'insurance',
        'too long' => 'Commercial property insurance protects the buildings and contents',
    ]);

    it('rejects an anchor already used by a link on the source page', function (): void {
        fakeAnchor(['anchor' => 'triggers the average clause']);

        $fixture = candidateProject();
        Link::factory()->from($fixture['source'])->to($fixture['target'])
            ->create(['anchor_text' => 'triggers the average clause']);

        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        expect($fixture['suggestion']->refresh()->anchor_text)->toBeNull();
    });

    it('rejects an anchor that sits inside a heading', function (): void {
        fakeAnchor(['anchor' => 'triggers the average clause']);

        $fixture = candidateProject(['headings' => ['How it triggers the average clause']]);
        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        expect($fixture['suggestion']->refresh()->anchor_text)->toBeNull();
    });

    it('accepts the model declining, and records it separately from a bad answer', function (): void {
        fakeAnchor(['anchor' => null]);

        $fixture = candidateProject();
        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        $suggestion = $fixture['suggestion']->refresh();

        expect($suggestion->anchor_text)->toBeNull()
            ->and($suggestion->status)->toBe(SuggestionStatus::Failed);
    });

    it('survives text that is not JSON at all', function (): void {
        fakeAnchor('I am afraid I cannot help with that.');

        $fixture = candidateProject();
        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        expect($fixture['suggestion']->refresh()->anchor_text)->toBeNull();
    });

    it('does not retry a candidate it has already failed', function (): void {
        fakeAnchor(['anchor' => 'invented phrase here']);

        $fixture = candidateProject();
        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));
        Http::assertSentCount(1);

        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        // Quota is finite; a candidate that failed validation is not worth
        // paying for twice.
        Http::assertSentCount(1);
    });
});

describe('the per-project cap', function (): void {
    it('only spends requests on the highest-priority candidates', function (): void {
        fakeAnchor(['anchor' => 'triggers the average clause']);
        config()->set('linkweaver.anchors.per_project', 2);

        $project = Project::factory()->create();
        $source = Page::factory()->for($project)->at('https://example.com/s/')->create([
            'content_text' => SOURCE_BODY, 'headings' => [],
        ]);

        $scores = [0.9, 0.8, 0.7, 0.6, 0.5];

        foreach ($scores as $i => $score) {
            $target = Page::factory()->for($project)->at("https://example.com/t{$i}/")->create();

            Suggestion::query()->create([
                'project_id' => $project->id,
                'source_page_id' => $source->id,
                'target_page_id' => $target->id,
                'similarity' => 0.8,
                'priority_score' => $score,
                'status' => SuggestionStatus::Pending,
            ]);
        }

        Bus::dispatch(new SuggestAnchorsJob($project));

        Http::assertSentCount(2);

        $withAnchors = Suggestion::query()->whereNotNull('anchor_text')
            ->pluck('priority_score')->map(fn ($s): float => (float) $s)->sort()->values()->all();

        expect($withAnchors)->toBe([0.8, 0.9]);
    });
});

describe('pipeline position', function (): void {
    it('marks the project done when it finishes', function (): void {
        fakeAnchor(['anchor' => 'triggers the average clause']);

        $fixture = candidateProject();
        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        expect($fixture['project']->refresh()->status)->toBe(ProjectStatus::Done);
    });

    it('completes without calling Gemini when no key is configured', function (): void {
        config()->set('linkweaver.gemini.api_key', '');
        Http::fake();

        $fixture = candidateProject();
        Bus::dispatch(new SuggestAnchorsJob($fixture['project']));

        expect($fixture['project']->refresh()->status)->toBe(ProjectStatus::Done);
        Http::assertNothingSent();
    });

    it('completes when there are no candidates at all', function (): void {
        Http::fake();
        $project = Project::factory()->create();

        Bus::dispatch(new SuggestAnchorsJob($project));

        expect($project->refresh()->status)->toBe(ProjectStatus::Done);
        Http::assertNothingSent();
    });
});

describe('request pacing', function (): void {
    // generateContent cannot be batched, so one run makes one request per
    // candidate. Job middleware only throttles the job, not the requests inside
    // it, so the job paces itself — otherwise GEMINI_REQUESTS_PER_MINUTE would
    // be a number the config claims to respect and does not.
    it('waits between requests according to the configured rate', function (): void {
        fakeAnchor(['anchor' => 'triggers the average clause']);
        config()->set('linkweaver.gemini.requests_per_minute', 30);

        $project = Project::factory()->create();
        $source = Page::factory()->for($project)->at('https://example.com/s/')->create([
            'content_text' => SOURCE_BODY, 'headings' => [],
        ]);

        foreach (range(1, 3) as $i) {
            $target = Page::factory()->for($project)->at("https://example.com/t{$i}/")->create();

            Suggestion::query()->create([
                'project_id' => $project->id,
                'source_page_id' => $source->id,
                'target_page_id' => $target->id,
                'similarity' => 0.8,
                'priority_score' => 0.8,
                'status' => SuggestionStatus::Pending,
            ]);
        }

        Bus::dispatch(new SuggestAnchorsJob($project));

        // 30 per minute is one every two seconds, once per candidate.
        Sleep::assertSleptTimes(3);
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === 2.0, 3);
    });

    it('paces faster when the configured rate allows it', function (): void {
        fakeAnchor(['anchor' => 'triggers the average clause']);
        config()->set('linkweaver.gemini.requests_per_minute', 60);

        Bus::dispatch(new SuggestAnchorsJob(candidateProject()['project']));

        Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === 1.0);
    });
});
