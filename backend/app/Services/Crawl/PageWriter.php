<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\Page;
use Illuminate\Support\Facades\DB;

/**
 * Persists one crawled page and the links found on it.
 *
 * Kept out of the job so the job body reads as orchestration. The interesting
 * part is link resolution: a link is matched to a page row by normalised URL,
 * and a link to a same-site URL the sitemap never listed is still recorded,
 * with a null target, because "this site links to pages it does not publish" is
 * a real finding.
 */
final class PageWriter
{
    public function store(Page $page, ExtractedPage $extracted, int $status): void
    {
        DB::transaction(function () use ($page, $extracted, $status): void {
            $page->forceFill([
                'title' => $this->truncate($extracted->title, 255),
                'h1' => $this->truncate($extracted->h1, 255),
                'headings' => $extracted->headings,
                'meta_description' => $extracted->metaDescription,
                'content_text' => $extracted->text,
                'content_hash' => $extracted->contentHash,
                'word_count' => $extracted->wordCount,
                'http_status' => $status,
                'crawl_error' => null,
                'crawled_at' => now(),
            ])->save();

            $this->storeLinks($page, $extracted);
        });
    }

    public function storeFailure(Page $page, string $error, ?int $status = null): void
    {
        $page->forceFill([
            'http_status' => $status,
            'crawl_error' => mb_substr($error, 0, 1000),
            'crawled_at' => now(),
        ])->save();
    }

    private function storeLinks(Page $page, ExtractedPage $extracted): void
    {
        // Replaced wholesale rather than merged: a re-crawl should reflect the
        // page as it is now, including links the author removed.
        $page->outboundLinks()->delete();

        if ($extracted->links === []) {
            return;
        }

        $targets = Page::query()
            ->where('project_id', $page->project_id)
            ->whereIn('normalized_url', array_map(
                static fn (ExtractedLink $link): string => $link->url,
                $extracted->links
            ))
            ->pluck('id', 'normalized_url');

        $now = now();

        $rows = array_map(static fn (ExtractedLink $link): array => [
            'project_id' => $page->project_id,
            'source_page_id' => $page->id,
            'target_page_id' => $targets[$link->url] ?? null,
            'target_url' => $link->url,
            'anchor_text' => mb_substr($link->anchorText, 0, 1000),
            'in_content' => $link->inContent,
            'created_at' => $now,
            'updated_at' => $now,
        ], $extracted->links);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('links')->insert($chunk);
        }
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
