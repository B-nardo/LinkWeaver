<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Crawl\CrawlToolkit;
use App\Services\Crawl\Exceptions\UnreadableSitemapException;
use App\Services\Crawl\Exceptions\UnsafeUrlException;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * First stage: turn a sitemap URL into page rows, then fan out the crawl.
 *
 * The HTTP request that created the project did none of this work — it only
 * validated the input and dispatched this job — so a slow or enormous sitemap
 * can never hold a web worker open.
 */
final class ParseSitemapJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly Project $project) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(CrawlToolkit $toolkit): void
    {
        $this->project->transitionTo(ProjectStatus::Crawling);

        try {
            $urls = $toolkit->collector($this->project)
                ->collect($this->project->sitemap_url, $this->project->skip_taxonomies);
        } catch (UnreadableSitemapException|UnsafeUrlException $e) {
            // Expected outcomes, not infrastructure failures: a malformed or
            // blocked sitemap will read exactly the same on the third attempt,
            // so it is reported to the user immediately instead of retried.
            $this->project->markFailed($e->getMessage());

            return;
        }

        if ($urls === []) {
            $this->project->markFailed(
                'The sitemap parsed successfully but contained no crawlable pages on this domain.'
            );

            return;
        }

        $this->createPages($urls);

        $this->project->forceFill([
            'pages_found' => count($urls),
            'pages_crawled' => 0,
        ])->save();

        $this->dispatchCrawl();
    }

    /**
     * @param  list<string>  $urls
     */
    private function createPages(array $urls): void
    {
        $now = now();
        $projectId = $this->project->id;

        $rows = array_map(static fn (string $url): array => [
            'project_id' => $projectId,
            'url' => $url,
            'normalized_url' => $url,
            'created_at' => $now,
            'updated_at' => $now,
        ], $urls);

        // Chunked to stay well inside MySQL's placeholder limit, and idempotent
        // so a retry of this job cannot violate the (project, url) unique index.
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('pages')->insertOrIgnore($chunk);
        }
    }

    /**
     * Fans the crawl out into a batch so progress is observable and one dead
     * page cannot abort the rest.
     */
    private function dispatchCrawl(): void
    {
        $project = $this->project;

        $jobs = $project->pages()
            ->whereNull('crawled_at')
            ->pluck('id')
            ->map(fn (int $pageId): CrawlPageJob => new CrawlPageJob($project, $pageId))
            ->all();

        if ($jobs === []) {
            $project->transitionTo(ProjectStatus::Done);

            return;
        }

        Bus::batch($jobs)
            ->name("crawl:{$project->id}")
            ->allowFailures()
            ->finally(static function () use ($project): void {
                // Phase 1 ends at a crawled site. Embedding and analysis become
                // the next links in this chain in phases 3 and 4.
                $project->refresh();

                if ($project->status !== ProjectStatus::Failed) {
                    $project->transitionTo(ProjectStatus::Done);
                }
            })
            ->dispatch();
    }

    public function failed(Throwable $exception): void
    {
        $this->project->markFailed(match (true) {
            $exception instanceof UnreadableSitemapException,
            $exception instanceof UnsafeUrlException => $exception->getMessage(),
            default => 'The sitemap could not be fetched. Check the URL is reachable and returns XML.',
        });
    }
}
