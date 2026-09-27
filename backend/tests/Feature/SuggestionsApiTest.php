<?php

declare(strict_types=1);

use App\Enums\SuggestionStatus;
use App\Models\Page;
use App\Models\Project;
use App\Models\Suggestion;
use App\Models\User;

/*
|------------------------------------------------------------------------------
| Suggestions API
|------------------------------------------------------------------------------
|
| The review surface: list, approve, reject, bulk, and the CSV a user takes
| away to actually do the work.
|
*/

/**
 * @return array{project: Project, user: User, suggestions: list<Suggestion>}
 */
function reviewableProject(?User $owner = null, int $count = 3): array
{
    $user = $owner ?? User::factory()->create();
    $project = Project::factory()->for($user)->done()->create();

    $source = Page::factory()->for($project)->at('https://example.com/source/')
        ->create(['title' => 'Source Page']);

    $suggestions = [];

    foreach (range(1, $count) as $i) {
        $target = Page::factory()->for($project)->at("https://example.com/target-{$i}/")
            ->create(['title' => "Target {$i}"]);

        $suggestions[] = Suggestion::query()->create([
            'project_id' => $project->id,
            'source_page_id' => $source->id,
            'target_page_id' => $target->id,
            'similarity' => 0.9 - ($i * 0.05),
            'priority_score' => 0.9 - ($i * 0.1),
            'anchor_text' => "anchor phrase {$i}",
            'context_sentence' => "A sentence with anchor phrase {$i} inside it.",
            'status' => SuggestionStatus::Pending,
        ]);
    }

    return ['project' => $project, 'user' => $user, 'suggestions' => $suggestions];
}

describe('listing', function (): void {
    // Public route, so a guest gets 404 rather than 401 — see PublicDemoTest.
    it('hides a real project from a guest', function (): void {
        $project = Project::factory()->create();

        $this->getJson("/api/projects/{$project->id}/suggestions")->assertNotFound();
    });

    it('hides another user project behind a 404', function (): void {
        $fixture = reviewableProject();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/projects/{$fixture['project']->id}/suggestions")
            ->assertNotFound();
    });

    it('returns suggestions with both pages and the anchor', function (): void {
        $fixture = reviewableProject();

        $response = $this->actingAs($fixture['user'])
            ->getJson("/api/projects/{$fixture['project']->id}/suggestions")
            ->assertOk();

        $first = $response->json('data.0');

        expect($response->json('data'))->toHaveCount(3)
            ->and($first['anchor_text'])->toBeString()
            ->and($first['context_sentence'])->toContain($first['anchor_text'])
            ->and($first['source']['title'])->toBe('Source Page')
            ->and($first['target']['title'])->toStartWith('Target');
    });

    it('orders by priority so the best opportunities come first', function (): void {
        $fixture = reviewableProject();

        $scores = $this->actingAs($fixture['user'])
            ->getJson("/api/projects/{$fixture['project']->id}/suggestions")
            ->json('data.*.priority_score');

        expect($scores)->toBe(collect($scores)->sortDesc()->values()->all());
    });

    it('hides candidates that have no anchor yet', function (): void {
        $fixture = reviewableProject();
        $fixture['suggestions'][0]->forceFill(['anchor_text' => null])->save();

        $response = $this->actingAs($fixture['user'])
            ->getJson("/api/projects/{$fixture['project']->id}/suggestions")
            ->assertOk();

        // A suggestion with no anchor is not reviewable; it is unfinished work.
        expect($response->json('data'))->toHaveCount(2);
    });

    it('filters by status', function (): void {
        $fixture = reviewableProject();
        $fixture['suggestions'][0]->forceFill(['status' => SuggestionStatus::Approved])->save();

        $response = $this->actingAs($fixture['user'])
            ->getJson("/api/projects/{$fixture['project']->id}/suggestions?status=approved")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1);
    });

    it('filters by minimum score', function (): void {
        $fixture = reviewableProject();

        $response = $this->actingAs($fixture['user'])
            ->getJson("/api/projects/{$fixture['project']->id}/suggestions?min_score=0.75")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1);
    });

    it('rejects a filter it does not recognise', function (string $query): void {
        $fixture = reviewableProject();

        $this->actingAs($fixture['user'])
            ->getJson("/api/projects/{$fixture['project']->id}/suggestions?{$query}")
            ->assertStatus(422);
    })->with(['status=nonsense', 'min_score=abc', 'per_page=9999']);
});

describe('approving and rejecting', function (): void {
    it('approves a suggestion', function (): void {
        $fixture = reviewableProject();
        $suggestion = $fixture['suggestions'][0];

        $this->actingAs($fixture['user'])
            ->patchJson("/api/suggestions/{$suggestion->id}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect($suggestion->refresh()->status)->toBe(SuggestionStatus::Approved);
    });

    it('rejects a suggestion', function (): void {
        $fixture = reviewableProject();
        $suggestion = $fixture['suggestions'][0];

        $this->actingAs($fixture['user'])
            ->patchJson("/api/suggestions/{$suggestion->id}", ['status' => 'rejected'])
            ->assertOk();

        expect($suggestion->refresh()->status)->toBe(SuggestionStatus::Rejected);
    });

    it('refuses a status the reviewer is not allowed to set', function (string $status): void {
        $fixture = reviewableProject();

        $this->actingAs($fixture['user'])
            ->patchJson("/api/suggestions/{$fixture['suggestions'][0]->id}", ['status' => $status])
            ->assertStatus(422);
    })->with(['applied', 'failed', 'pending', 'nonsense']);

    it('will not let one user touch another user suggestion', function (): void {
        $fixture = reviewableProject();

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/suggestions/{$fixture['suggestions'][0]->id}", ['status' => 'approved'])
            ->assertNotFound();

        expect($fixture['suggestions'][0]->refresh()->status)->toBe(SuggestionStatus::Pending);
    });
});

describe('bulk actions', function (): void {
    it('approves several at once', function (): void {
        $fixture = reviewableProject();
        $ids = collect($fixture['suggestions'])->pluck('id')->take(2)->all();

        $this->actingAs($fixture['user'])
            ->postJson("/api/projects/{$fixture['project']->id}/suggestions/bulk", [
                'ids' => $ids,
                'status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('updated', 2);

        expect(Suggestion::query()->whereIn('id', $ids)
            ->where('status', SuggestionStatus::Approved)->count())->toBe(2);
    });

    it('ignores ids belonging to another project', function (): void {
        $mine = reviewableProject();
        $theirs = reviewableProject();

        $this->actingAs($mine['user'])
            ->postJson("/api/projects/{$mine['project']->id}/suggestions/bulk", [
                'ids' => [$theirs['suggestions'][0]->id],
                'status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('updated', 0);

        expect($theirs['suggestions'][0]->refresh()->status)->toBe(SuggestionStatus::Pending);
    });

    it('validates the payload', function (array $payload): void {
        $fixture = reviewableProject();

        $this->actingAs($fixture['user'])
            ->postJson("/api/projects/{$fixture['project']->id}/suggestions/bulk", $payload)
            ->assertStatus(422);
    })->with([
        'no ids' => [['status' => 'approved']],
        'empty ids' => [['ids' => [], 'status' => 'approved']],
        'bad status' => [['ids' => [1], 'status' => 'applied']],
    ]);
});

describe('CSV export', function (): void {
    it('exports only approved suggestions', function (): void {
        $fixture = reviewableProject();
        $fixture['suggestions'][0]->forceFill(['status' => SuggestionStatus::Approved])->save();

        $response = $this->actingAs($fixture['user'])
            ->get("/api/projects/{$fixture['project']->id}/export.csv")
            ->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toContain('source_url')
            ->and($lines[1])->toContain('anchor phrase 1');
    });

    it('is sent as a downloadable csv', function (): void {
        $fixture = reviewableProject();

        $response = $this->actingAs($fixture['user'])
            ->get("/api/projects/{$fixture['project']->id}/export.csv")
            ->assertOk();

        expect($response->headers->get('content-type'))->toContain('text/csv')
            ->and($response->headers->get('content-disposition'))->toContain('attachment');
    });

    it('still returns a header row when nothing is approved', function (): void {
        $fixture = reviewableProject();

        $csv = $this->actingAs($fixture['user'])
            ->get("/api/projects/{$fixture['project']->id}/export.csv")
            ->assertOk()
            ->streamedContent();

        expect(trim($csv))->toContain('source_url');
    });

    it('escapes content that would otherwise break the csv', function (): void {
        $fixture = reviewableProject();
        $fixture['suggestions'][0]->forceFill([
            'status' => SuggestionStatus::Approved,
            'anchor_text' => 'anchor, with "quotes"',
            'context_sentence' => 'A sentence
split across lines.',
        ])->save();

        $csv = $this->actingAs($fixture['user'])
            ->get("/api/projects/{$fixture['project']->id}/export.csv")
            ->assertOk()
            ->streamedContent();

        // Parsed through a real CSV reader rather than by splitting on newlines,
        // because a correctly escaped field may itself contain one.
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        expect($rows)->toHaveCount(2)
            ->and($rows[1])->toContain('anchor, with "quotes"')
            ->and($rows[1])->toContain('A sentence
split across lines.');
    });

    it('refuses another user project', function (): void {
        $fixture = reviewableProject();

        $this->actingAs(User::factory()->create())
            ->get("/api/projects/{$fixture['project']->id}/export.csv")
            ->assertNotFound();
    });
});
