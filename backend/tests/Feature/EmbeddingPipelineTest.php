<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Jobs\AnalyzeProjectJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Models\Embedding;
use App\Models\Page;
use App\Models\Project;
use App\Models\Suggestion;
use App\Services\Analysis\SimilarityCalculator;
use App\Services\Gemini\Exceptions\GeminiTransientException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/*
|------------------------------------------------------------------------------
| Embedding and analysis pipeline
|------------------------------------------------------------------------------
|
| Spec 11: no test may touch the real Gemini API. Every response here is faked.
|
*/

const EMBED_MODEL = 'gemini-embedding-2';

beforeEach(function (): void {
    config()->set('linkweaver.gemini.api_key', 'test-key');
    config()->set('linkweaver.gemini.embed_model', EMBED_MODEL);
    config()->set('linkweaver.gemini.embed_dimensions', 3);
    config()->set('linkweaver.gemini.embed_batch_size', 50);
});

/**
 * Fakes Gemini, returning a distinct vector for each input in a batch.
 */
function fakeGemini(?callable $vectorFor = null): void
{
    $vectorFor ??= static fn (int $index): array => [1.0, $index * 0.01, 0.0];

    Http::fake([
        '*batchEmbedContents' => function (Request $request) use ($vectorFor) {
            $count = count($request->data()['requests']);

            return Http::response([
                'embeddings' => array_map(
                    static fn (int $i): array => ['values' => $vectorFor($i)],
                    range(0, $count - 1)
                ),
            ]);
        },
    ]);
}

function projectWithPages(int $count = 3): Project
{
    $project = Project::factory()->create(['status' => ProjectStatus::Crawling]);

    foreach (range(1, $count) as $i) {
        Page::factory()->for($project)->at("https://example.com/page-{$i}/")->create([
            'title' => "Page {$i}",
            'content_text' => "Content for page {$i}.",
            'content_hash' => hash('sha256', "page-{$i}"),
        ]);
    }

    return $project;
}

describe('generating embeddings', function (): void {
    it('stores one embedding per crawled page', function (): void {
        fakeGemini();
        $project = projectWithPages(3);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        expect(Embedding::query()->count())->toBe(3);
    });

    it('stores vectors already normalised to unit length', function (): void {
        fakeGemini(static fn (int $i): array => [3.0, 4.0, 0.0]);
        $project = projectWithPages(1);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        $vector = Embedding::query()->sole()->vector;

        expect(SimilarityCalculator::magnitude($vector))->toEqualWithDelta(1.0, 1e-9);
    });

    it('records the model and content hash it embedded', function (): void {
        fakeGemini();
        $project = projectWithPages(1);
        $page = $project->pages()->sole();

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        $embedding = Embedding::query()->sole();

        expect($embedding->model)->toBe(EMBED_MODEL)
            ->and($embedding->content_hash)->toBe($page->content_hash);
    });

    it('counts embedded pages so the frontend can show progress', function (): void {
        fakeGemini();
        $project = projectWithPages(3);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        expect($project->refresh()->pages_embedded)->toBe(3);
    });

    it('skips pages that were never successfully crawled', function (): void {
        fakeGemini();
        $project = projectWithPages(2);
        Page::factory()->for($project)->at('https://example.com/broken/')->failed()->create();

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        expect(Embedding::query()->count())->toBe(2);
    });

    it('sends the title and content as one text', function (): void {
        fakeGemini();
        $project = projectWithPages(1);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        Http::assertSent(function (Request $request): bool {
            $text = $request->data()['requests'][0]['content']['parts'][0]['text'];

            return str_contains($text, 'Page 1') && str_contains($text, 'Content for page 1.');
        });
    });

    it('splits large projects into batches', function (): void {
        fakeGemini();
        config()->set('linkweaver.gemini.embed_batch_size', 2);
        $project = projectWithPages(5);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        // 5 pages at 2 per batch is three calls.
        Http::assertSentCount(3);
        expect(Embedding::query()->count())->toBe(5);
    });
});

describe('the content-hash cache', function (): void {
    it('does not re-embed a page whose content has not changed', function (): void {
        fakeGemini();
        $project = projectWithPages(2);

        Bus::dispatch(new GenerateEmbeddingsJob($project));
        Http::assertSentCount(1);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        // Nothing changed, so the second run must spend no quota at all.
        Http::assertSentCount(1);
        expect(Embedding::query()->count())->toBe(2);
    });

    it('re-embeds a page whose content changed', function (): void {
        fakeGemini();
        $project = projectWithPages(1);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        $project->pages()->sole()->forceFill([
            'content_text' => 'Rewritten entirely.',
            'content_hash' => hash('sha256', 'rewritten'),
        ])->save();

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        expect(Http::recorded()->count())->toBe(2)
            ->and(Embedding::query()->count())->toBe(2);
    });

    it('re-embeds everything when the model changes', function (): void {
        fakeGemini();
        $project = projectWithPages(1);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        config()->set('linkweaver.gemini.embed_model', 'some-newer-model');

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        // Vectors from two models are not comparable, so the cache key
        // deliberately includes the model.
        expect(Embedding::query()->count())->toBe(2);
    });
});

describe('failure handling', function (): void {
    it('lets a rate limit bubble up so the queue retries it', function (): void {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);
        $project = projectWithPages(1);

        Bus::dispatch(new GenerateEmbeddingsJob($project));
    })->throws(GeminiTransientException::class);

    it('fails the project on an error retrying cannot fix', function (): void {
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);
        $project = projectWithPages(1);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        expect($project->refresh()->status)->toBe(ProjectStatus::Failed)
            ->and($project->error_message)->toContain('API key not valid');
    });
});

describe('running without Gemini configured', function (): void {
    it('completes the project instead of failing it', function (): void {
        // Phase 2's promise is that the audit is useful with no AI at all.
        // A missing key should therefore stop the pipeline, not break it.
        config()->set('linkweaver.gemini.api_key', '');
        Http::fake();

        $project = projectWithPages(2);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        expect($project->refresh()->status)->toBe(ProjectStatus::Done)
            ->and(Embedding::query()->count())->toBe(0);

        Http::assertNothingSent();
    });
});

describe('analysis', function (): void {
    it('turns embeddings into scored candidates', function (): void {
        fakeGemini(static fn (int $i): array => [1.0, $i * 0.01, 0.0]);
        $project = projectWithPages(3);

        Bus::dispatch(new GenerateEmbeddingsJob($project));
        Bus::dispatch(new AnalyzeProjectJob($project));

        expect(Suggestion::query()->count())->toBeGreaterThan(0)
            ->and($project->refresh()->status)->toBe(ProjectStatus::Done);
    });

    it('marks the project done even when nothing is similar enough', function (): void {
        fakeGemini(static fn (int $i): array => $i === 0 ? [1.0, 0.0, 0.0] : [0.0, 0.0, 1.0]);
        $project = projectWithPages(2);

        Bus::dispatch(new GenerateEmbeddingsJob($project));
        Bus::dispatch(new AnalyzeProjectJob($project));

        expect(Suggestion::query()->count())->toBe(0)
            ->and($project->refresh()->status)->toBe(ProjectStatus::Done);
    });

    it('chains straight into analysis after embedding', function (): void {
        Bus::fake([AnalyzeProjectJob::class]);
        fakeGemini();
        $project = projectWithPages(2);

        Bus::dispatch(new GenerateEmbeddingsJob($project));

        Bus::assertDispatched(AnalyzeProjectJob::class);
    });
});
