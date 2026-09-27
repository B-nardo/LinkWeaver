<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProjectStatus;
use App\Enums\SuggestionStatus;
use App\Models\Page;
use App\Models\Project;
use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds the public demo project from saved fixture data (spec 9).
 *
 * Deliberately performs no crawling and makes no Gemini call: the demo has to
 * work on a cold container with no API key, on a free tier, for a visitor who
 * has not signed up. It is the first thing anyone sees, so it cannot depend on
 * a third-party service being reachable.
 *
 * Embeddings are not seeded, because nothing the demo displays reads them. The
 * graph, the pages table and the suggestions all render from pages, links and
 * suggestions, which keeps the fixture at tens of kilobytes rather than the
 * megabytes a full set of vectors would cost.
 *
 * Idempotent: the container runs migrations and seeds on every boot.
 */
final class DemoProjectSeeder extends Seeder
{
    private const string FIXTURE = 'fixtures/demo-project.json';

    private const string DEMO_EMAIL = 'demo@linkweaver.app';

    private const string SITEMAP_URL = 'https://example-brokers.test/sitemap.xml';

    public function run(): void
    {
        $fixture = $this->fixture();

        $owner = User::query()->firstOrCreate(
            ['email' => self::DEMO_EMAIL],
            ['name' => 'Linkweaver Demo', 'password' => bin2hex(random_bytes(24))],
        );

        // Replaced wholesale rather than merged, so a changed fixture is
        // reflected exactly and repeated boots cannot accumulate duplicates.
        Project::query()->where('is_demo', true)->delete();

        $project = $owner->projects()->create([
            'name' => 'Example Brokers',
            'sitemap_url' => self::SITEMAP_URL,
        ]);

        $project->forceFill([
            'is_demo' => true,
            'status' => ProjectStatus::Done,
            'pages_found' => count($fixture['pages']),
            'pages_crawled' => count($fixture['pages']),
            'pages_embedded' => count($fixture['pages']),
        ])->save();

        $pageIds = $this->seedPages($project, $fixture['pages']);

        $this->seedLinks($project, $fixture['links'], $pageIds);
        $this->seedSuggestions($project, $fixture['suggestions'], $pageIds);

        $this->command?->info(sprintf(
            'Demo project seeded: %d pages, %d links, %d suggestions.',
            count($fixture['pages']),
            count($fixture['links']),
            count($fixture['suggestions']),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, int> slug => page id
     */
    private function seedPages(Project $project, array $pages): array
    {
        $ids = [];

        foreach ($pages as $page) {
            $url = 'https://example-brokers.test/'.$page['slug'].'/';

            $model = Page::query()->create([
                'project_id' => $project->id,
                'url' => $url,
                'normalized_url' => $url,
                'title' => $page['title'],
                'h1' => $page['title'],
                'headings' => $page['headings'],
                'meta_description' => $page['meta_description'],
                'content_text' => $page['content_text'],
                'content_hash' => hash('sha256', $page['content_text']),
                'word_count' => $page['word_count'],
                'http_status' => 200,
                'crawled_at' => now(),
            ]);

            $ids[$page['slug']] = (int) $model->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $links
     * @param  array<string, int>  $pageIds
     */
    private function seedLinks(Project $project, array $links, array $pageIds): void
    {
        $rows = [];
        $now = now();

        foreach ($links as $link) {
            $rows[] = [
                'project_id' => $project->id,
                'source_page_id' => $pageIds[$link['source']],
                'target_page_id' => $pageIds[$link['target']],
                'target_url' => 'https://example-brokers.test/'.$link['target'].'/',
                'anchor_text' => $link['anchor_text'],
                'in_content' => $link['in_content'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('links')->insert($chunk);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     * @param  array<string, int>  $pageIds
     */
    private function seedSuggestions(Project $project, array $suggestions, array $pageIds): void
    {
        foreach ($suggestions as $suggestion) {
            Suggestion::query()->create([
                'project_id' => $project->id,
                'source_page_id' => $pageIds[$suggestion['source']],
                'target_page_id' => $pageIds[$suggestion['target']],
                'similarity' => $suggestion['similarity'],
                'priority_score' => $suggestion['priority_score'],
                'anchor_text' => $suggestion['anchor_text'],
                'context_sentence' => $suggestion['context_sentence'],
                'status' => SuggestionStatus::Pending,
            ]);
        }
    }

    /**
     * @return array{pages: list<array<string, mixed>>, links: list<array<string, mixed>>, suggestions: list<array<string, mixed>>}
     */
    private function fixture(): array
    {
        $path = database_path(self::FIXTURE);

        if (! is_file($path)) {
            throw new RuntimeException("Demo fixture missing at {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['pages'], $decoded['links'], $decoded['suggestions'])) {
            throw new RuntimeException('Demo fixture is malformed.');
        }

        return $decoded;
    }
}
