<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Middleware\ThrottlePerDomain;
use App\Models\Page;
use App\Models\Project;
use App\Services\Crawl\CrawlToolkit;
use App\Services\Crawl\Exceptions\UnsafeUrlException;
use App\Services\Crawl\PageWriter;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fetches and extracts a single page.
 *
 * One job per page so a single slow or broken URL cannot stall the others, and
 * so batch progress reflects real work. Failures are recorded on the page rather
 * than thrown: a site with a handful of dead URLs should still produce a usable
 * audit of the rest.
 */
final class CrawlPageJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly Project $project,
        public readonly int $pageId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new ThrottlePerDomain(
                host: (string) parse_url($this->project->sitemap_url, PHP_URL_HOST),
                requestsPerSecond: (int) config('linkweaver.throttle.requests_per_second'),
            ),
        ];
    }

    public function handle(CrawlToolkit $toolkit, PageWriter $writer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $page = Page::query()->find($this->pageId);

        if ($page === null) {
            return;
        }

        try {
            $this->crawl($page, $toolkit, $writer);
        } catch (UnsafeUrlException $e) {
            // Not retryable: the destination is blocked on principle, and asking
            // again will reach the same conclusion.
            $writer->storeFailure($page, $e->getMessage());
        } catch (ConnectionException $e) {
            $writer->storeFailure($page, 'Could not connect: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $writer->storeFailure($page, 'The page could not be processed.');
        } finally {
            $this->recordProgress();
        }
    }

    private function crawl(Page $page, CrawlToolkit $toolkit, PageWriter $writer): void
    {
        if (config('linkweaver.robots.respect')
            && ! $toolkit->robots()->for($page->url)->allows($page->url)) {
            $writer->storeFailure($page, 'Skipped: disallowed by robots.txt.');

            return;
        }

        $response = $toolkit->fetcher()->fetch($page->url);

        if ($response->status >= 400) {
            $writer->storeFailure($page, "The server returned HTTP {$response->status}.", $response->status);

            return;
        }

        if (! $response->isHtml()) {
            $writer->storeFailure(
                $page,
                'Skipped: the URL did not return HTML ('.($response->contentType ?? 'unknown type').').',
                $response->status
            );

            return;
        }

        $writer->store(
            $page,
            $toolkit->extractor($this->project)->extract($response->body, $response->finalUrl),
            $response->status
        );
    }

    /**
     * Incremented atomically: many of these jobs run concurrently, and a
     * read-modify-write would lose counts and stall the progress display.
     */
    private function recordProgress(): void
    {
        DB::table('projects')
            ->where('id', $this->project->id)
            ->increment('pages_crawled');
    }

    public function failed(Throwable $exception): void
    {
        $page = Page::query()->find($this->pageId);

        if ($page !== null && $page->crawled_at === null) {
            app(PageWriter::class)->storeFailure($page, 'The page could not be crawled.');
        }
    }
}
