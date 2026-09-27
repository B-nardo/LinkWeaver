<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Models\Embedding;
use App\Models\Page;
use App\Models\Project;
use App\Services\Analysis\SimilarityCalculator;
use App\Services\Gemini\EmbeddingText;
use App\Services\Gemini\Exceptions\GeminiConfigurationException;
use App\Services\Gemini\Exceptions\GeminiRequestException;
use App\Services\Gemini\GeminiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Embeds every page whose content is not already embedded (spec 5.5).
 *
 * The cache is the point. A page is embedded only when no row exists for its
 * exact `(page_id, model, content_hash)`, so re-running a project spends quota
 * only on pages that actually changed, and switching models re-embeds
 * everything rather than comparing vectors that were never comparable.
 */
final class GenerateEmbeddingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(public readonly Project $project) {}

    /**
     * Exponential backoff, because the thing most likely to fail here is a
     * quota limit that clears with time.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new RateLimited('gemini'))->dontRelease(),
        ];
    }

    public function handle(GeminiClient $gemini): void
    {
        $model = (string) config('linkweaver.gemini.embed_model');
        $apiKey = (string) config('linkweaver.gemini.api_key');

        // Without Gemini configured the crawl and link analysis still stand on
        // their own, which is the whole promise of phase 2. Stopping cleanly
        // here is more useful than failing a project that has real results.
        if ($apiKey === '' || $model === '') {
            $this->project->transitionTo(ProjectStatus::Done);

            return;
        }

        $this->project->transitionTo(ProjectStatus::Embedding);

        try {
            $this->embedPending($gemini, $model);
        } catch (GeminiRequestException|GeminiConfigurationException $e) {
            // Retrying will produce the identical rejection, so report it now
            // rather than burning four more attempts on it.
            $this->project->markFailed($e->getMessage());

            return;
        }

        AnalyzeProjectJob::dispatch($this->project);
    }

    private function embedPending(GeminiClient $gemini, string $model): void
    {
        $dimensions = (int) config('linkweaver.gemini.embed_dimensions');
        $wordLimit = (int) config('linkweaver.gemini.embed_words');
        $batchSize = max(1, (int) config('linkweaver.gemini.embed_batch_size'));

        $this->pendingPages($model)
            ->chunkById($batchSize, function ($pages) use ($gemini, $model, $dimensions, $wordLimit): void {
                $texts = [];
                $embeddable = [];

                foreach ($pages as $page) {
                    $text = EmbeddingText::build(
                        $page->title,
                        $page->h1,
                        $page->content_text,
                        $wordLimit,
                    );

                    // A page with nothing extractable has no topic to compare;
                    // sending an empty string would waste a request and return
                    // a vector that means nothing.
                    if ($text === '') {
                        continue;
                    }

                    $texts[] = $text;
                    $embeddable[] = $page;
                }

                if ($texts === []) {
                    return;
                }

                $vectors = $gemini->embedBatch($texts, $model, $dimensions);

                $this->store($embeddable, $vectors, $model);
            });
    }

    /**
     * Pages that have been crawled successfully and have no current embedding.
     */
    private function pendingPages(string $model)
    {
        return $this->project->pages()
            ->whereNotNull('content_hash')
            ->whereNull('crawl_error')
            ->whereNotExists(function ($query) use ($model): void {
                $query->select(DB::raw(1))
                    ->from('embeddings')
                    ->whereColumn('embeddings.page_id', 'pages.id')
                    ->whereColumn('embeddings.content_hash', 'pages.content_hash')
                    ->where('embeddings.model', $model);
            });
    }

    /**
     * @param  iterable<Page>  $pages
     * @param  list<list<float>>  $vectors
     */
    private function store(iterable $pages, array $vectors, string $model): void
    {
        $stored = 0;

        foreach ($pages as $index => $page) {
            $vector = $vectors[$index] ?? null;

            if ($vector === null) {
                continue;
            }

            Embedding::query()->updateOrCreate(
                [
                    'page_id' => $page->id,
                    'model' => $model,
                    'content_hash' => $page->content_hash,
                ],
                [
                    // Normalised on the way in, so every later comparison is a
                    // dot product rather than a cosine.
                    'vector' => SimilarityCalculator::normalize($vector),
                ]
            );

            $stored++;
        }

        if ($stored > 0) {
            DB::table('projects')
                ->where('id', $this->project->id)
                ->increment('pages_embedded', $stored);
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->project->markFailed(
            'Embedding failed after several attempts: '.$exception->getMessage()
        );
    }
}
