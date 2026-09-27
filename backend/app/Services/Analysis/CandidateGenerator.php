<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\SuggestionStatus;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Finds pages that should link to each other but do not (spec 5.6).
 *
 * Every vector for the project is loaded into memory and compared with every
 * other. That is O(n^2) — about 20,000 comparisons at the 200-page cap, which
 * is milliseconds, and the reason `LINKWEAVER_MAX_PAGES` exists. Past a few
 * thousand pages this wants a vector database; the README says so.
 *
 * Two exclusions do most of the work of making the output credible:
 *
 *   - a pair the source already links to editorially is not an opportunity,
 *     and suggesting it would spend the tool's credibility telling people to
 *     do what they have already done;
 *   - navigation links do not count as "already linked", because a footer link
 *     is not the editorial link being proposed.
 */
final class CandidateGenerator
{
    public function __construct(
        private readonly string $model,
        private readonly float $threshold,
        private readonly int $candidatesPerPage,
        private readonly float $similarityWeight,
        private readonly float $scarcityWeight,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            model: (string) config('linkweaver.gemini.embed_model'),
            threshold: (float) config('linkweaver.analysis.similarity_threshold'),
            candidatesPerPage: (int) config('linkweaver.analysis.candidates_per_page'),
            similarityWeight: (float) config('linkweaver.analysis.similarity_weight'),
            scarcityWeight: (float) config('linkweaver.analysis.scarcity_weight'),
        );
    }

    /**
     * @return int the number of candidates written
     */
    public function generate(Project $project): int
    {
        $vectors = $this->loadVectors($project);

        if (count($vectors) < 2) {
            return 0;
        }

        $existingLinks = $this->existingEditorialLinks($project);
        $inbound = $this->inboundCounts($project);

        $rows = [];
        $now = now();

        foreach ($vectors as $sourceId => $sourceVector) {
            $scored = [];

            foreach ($vectors as $targetId => $targetVector) {
                if ($sourceId === $targetId) {
                    continue;
                }

                if (isset($existingLinks[$sourceId][$targetId])) {
                    continue;
                }

                $similarity = SimilarityCalculator::dot($sourceVector, $targetVector);

                if ($similarity < $this->threshold) {
                    continue;
                }

                $scored[] = [
                    'target' => $targetId,
                    'similarity' => $similarity,
                    'priority' => PriorityScore::calculate(
                        $similarity,
                        $inbound[$targetId] ?? 0,
                        $this->similarityWeight,
                        $this->scarcityWeight,
                    ),
                ];
            }

            // Ranked by similarity, because "the top N most similar" is what
            // the spec asks for; priority then orders the review queue.
            usort($scored, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

            foreach (array_slice($scored, 0, $this->candidatesPerPage) as $candidate) {
                $rows[] = [
                    'project_id' => $project->id,
                    'source_page_id' => $sourceId,
                    'target_page_id' => $candidate['target'],
                    'similarity' => $candidate['similarity'],
                    'priority_score' => $candidate['priority'],
                    'anchor_text' => null,
                    'context_sentence' => null,
                    'status' => SuggestionStatus::Pending->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $this->persist($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persist(array $rows): int
    {
        $written = 0;

        // insertOrIgnore against the unique (source, target) index, so
        // re-running analysis refreshes nothing and duplicates nothing.
        foreach (array_chunk($rows, 100) as $chunk) {
            $written += DB::table('suggestions')->insertOrIgnore($chunk);
        }

        return $written;
    }

    /**
     * Normalised vectors for every page in the project that has an embedding
     * from the current model.
     *
     * @return array<int, list<float>> page id => vector
     */
    private function loadVectors(Project $project): array
    {
        $rows = DB::table('embeddings')
            ->join('pages', 'pages.id', '=', 'embeddings.page_id')
            ->where('pages.project_id', $project->id)
            // Vectors from a different model are not comparable with these,
            // and silently mixing them would produce confident nonsense.
            ->where('embeddings.model', $this->model)
            ->select('embeddings.page_id', 'embeddings.vector')
            ->get();

        $vectors = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->vector, true);

            if (is_array($decoded) && $decoded !== []) {
                $vectors[(int) $row->page_id] = array_map(
                    static fn (mixed $value): float => (float) $value,
                    array_values($decoded)
                );
            }
        }

        return $vectors;
    }

    /**
     * @return array<int, array<int, true>> source id => set of target ids
     */
    private function existingEditorialLinks(Project $project): array
    {
        $links = DB::table('links')
            ->where('project_id', $project->id)
            ->where('in_content', true)
            ->whereNotNull('target_page_id')
            ->select('source_page_id', 'target_page_id')
            ->get();

        $map = [];

        foreach ($links as $link) {
            $map[(int) $link->source_page_id][(int) $link->target_page_id] = true;
        }

        return $map;
    }

    /**
     * @return array<int, int> page id => inbound editorial link count
     */
    private function inboundCounts(Project $project): array
    {
        return DB::table('links')
            ->where('project_id', $project->id)
            ->where('in_content', true)
            ->whereNotNull('target_page_id')
            ->whereColumn('source_page_id', '!=', 'target_page_id')
            ->groupBy('target_page_id')
            ->pluck(DB::raw('COUNT(*)'), 'target_page_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }
}
