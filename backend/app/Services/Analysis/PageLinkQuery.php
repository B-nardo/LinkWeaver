<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\PageClassification;
use App\Models\Page;
use App\Models\Project;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Counts how well connected each page in a project is.
 *
 * Counts are derived on read rather than stored. The composite index on
 * `links(project_id, target_page_id, in_content)` already covers the aggregate,
 * projects are capped at a few hundred pages, and a denormalised counter would
 * be one more thing that can silently drift out of date with the links it is
 * supposed to describe.
 *
 * The counting is done with joined sub-queries rather than `withCount`, because
 * the result has to be sortable and filterable at the database level for
 * pagination to be correct.
 */
final class PageLinkQuery
{
    /**
     * Sort keys accepted from the query string, mapped to expressions. An
     * allow-list, so a sort parameter can never reach SQL as-is.
     */
    private const array SORTS = [
        'inbound' => 'inbound_count',
        'outbound' => 'outbound_count',
        'title' => 'pages.title',
        'words' => 'pages.word_count',
        'url' => 'pages.normalized_url',
    ];

    public function __construct(private readonly int $weakThreshold) {}

    public static function fromConfig(): self
    {
        return new self((int) config('linkweaver.analysis.weak_inbound_threshold'));
    }

    /**
     * Pages with `inbound_count` and `outbound_count` attached.
     *
     * @return Builder<Page>
     */
    public function for(Project $project): Builder
    {
        return Page::query()
            ->where('pages.project_id', $project->id)
            ->leftJoinSub(
                $this->countsBy($project, 'target_page_id'),
                'inbound',
                'inbound.target_page_id',
                '=',
                'pages.id'
            )
            ->leftJoinSub(
                $this->countsBy($project, 'source_page_id'),
                'outbound',
                'outbound.source_page_id',
                '=',
                'pages.id'
            )
            ->select('pages.*')
            ->selectRaw('COALESCE(inbound.total, 0) as inbound_count')
            ->selectRaw('COALESCE(outbound.total, 0) as outbound_count');
    }

    /**
     * @return Builder<Page>
     */
    public function filtered(
        Project $project,
        ?string $filter = null,
        string $sort = 'inbound',
        string $direction = 'asc',
    ): Builder {
        $query = $this->for($project);

        match ($filter) {
            'orphan' => $query->whereRaw('COALESCE(inbound.total, 0) = 0'),
            'weak' => $query->whereRaw(
                'COALESCE(inbound.total, 0) BETWEEN 1 AND ?',
                [$this->weakThreshold]
            ),
            'attention' => $query->whereRaw(
                'COALESCE(inbound.total, 0) <= ?',
                [$this->weakThreshold]
            ),
            'linked' => $query->whereRaw(
                'COALESCE(inbound.total, 0) > ?',
                [$this->weakThreshold]
            ),
            default => null,
        };

        $column = self::SORTS[$sort] ?? self::SORTS['inbound'];
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query
            ->orderByRaw("{$column} {$direction}")
            // Ties are common — most pages have zero inbound links — so a
            // deterministic tiebreaker is what keeps pagination from repeating
            // or skipping rows between pages.
            ->orderBy('pages.id');
    }

    /**
     * Totals for the project overview.
     *
     * @return array{pages:int, orphans:int, weak:int, linked:int, edges:int, uncrawled:int}
     */
    public function summary(Project $project): array
    {
        $counts = [
            PageClassification::Orphan->value => 0,
            PageClassification::Weak->value => 0,
            PageClassification::Linked->value => 0,
        ];

        $pages = $this->for($project)->get();

        foreach ($pages as $page) {
            $classification = PageClassification::fromInboundCount(
                (int) $page->inbound_count,
                $this->weakThreshold
            );

            $counts[$classification->value]++;
        }

        return [
            'pages' => $pages->count(),
            'orphans' => $counts[PageClassification::Orphan->value],
            'weak' => $counts[PageClassification::Weak->value],
            'linked' => $counts[PageClassification::Linked->value],
            'edges' => $this->editorialLinks($project)->count(),
            // Surfaced rather than hidden: a page that failed to crawl has
            // unknown outbound links, so anything it would have linked to may
            // be reported as an orphan when it is not.
            'uncrawled' => $project->pages()
                ->where(fn ($query) => $query->whereNull('crawled_at')->orWhereNotNull('crawl_error'))
                ->count(),
        ];
    }

    /**
     * The links that count as real internal links: written into the body of a
     * page, pointing at another page we actually crawled.
     */
    public function editorialLinks(Project $project): QueryBuilder
    {
        return DB::table('links')
            ->where('project_id', $project->id)
            ->where('in_content', true)
            ->whereNotNull('target_page_id')
            // A page cannot rescue itself from orphanhood by linking to itself.
            ->whereColumn('source_page_id', '!=', 'target_page_id');
    }

    private function countsBy(Project $project, string $column): QueryBuilder
    {
        return $this->editorialLinks($project)
            ->select($column, DB::raw('COUNT(*) as total'))
            ->groupBy($column);
    }
}
