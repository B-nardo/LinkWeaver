<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\PageClassification;
use App\Models\Page;
use App\Models\Project;

/**
 * Builds the node-and-edge payload behind the link graph.
 *
 * Only editorial links become edges. Drawing navigation links would produce a
 * dense hairball in which every page connects to every other, which is both
 * useless to look at and actively misleading: the orphans would disappear into
 * the mesh, and they are the entire point of the picture.
 */
final class LinkGraphBuilder
{
    public function __construct(
        private readonly PageLinkQuery $pages,
        private readonly int $weakThreshold,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            PageLinkQuery::fromConfig(),
            (int) config('linkweaver.analysis.weak_inbound_threshold'),
        );
    }

    /**
     * @return array{
     *     nodes: list<array<string, mixed>>,
     *     edges: list<array{source:int, target:int}>,
     *     summary: array<string, int>
     * }
     */
    public function build(Project $project): array
    {
        $nodes = $this->pages->for($project)->get()
            ->map(fn (Page $page): array => [
                'id' => $page->id,
                'url' => $page->normalized_url,
                // Falls back to the URL so a node is never an unlabelled dot,
                // which matters for the table alternative as much as the graph.
                'title' => $page->title ?? $page->normalized_url,
                'inbound' => (int) $page->inbound_count,
                'outbound' => (int) $page->outbound_count,
                'classification' => PageClassification::fromInboundCount(
                    (int) $page->inbound_count,
                    $this->weakThreshold
                )->value,
                'crawled' => $page->crawled_at !== null && $page->crawl_error === null,
            ])
            ->values()
            ->all();

        $edges = $this->pages->editorialLinks($project)
            ->select('source_page_id', 'target_page_id')
            ->get()
            ->map(fn (object $link): array => [
                'source' => (int) $link->source_page_id,
                'target' => (int) $link->target_page_id,
            ])
            ->values()
            ->all();

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'summary' => $this->pages->summary($project),
        ];
    }
}
