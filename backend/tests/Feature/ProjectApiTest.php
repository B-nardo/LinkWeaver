<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Jobs\ParseSitemapJob;
use App\Models\Project;
use App\Models\User;
use App\Services\Crawl\Dns\DnsResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/*
|------------------------------------------------------------------------------
| Project API
|------------------------------------------------------------------------------
|
| DNS is faked for the whole file: creating a project validates the sitemap URL
| through SafeUrlGuard, which resolves the host. No test may touch the network.
|
*/

beforeEach(function (): void {
    $this->app->bind(DnsResolver::class, fn (): DnsResolver => new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return match ($host) {
                'example.com', 'www.example.com' => ['93.184.216.34'],
                'internal.test' => ['127.0.0.1'],
                default => [],
            };
        }
    });
});

describe('authentication', function (): void {
    it('rejects an unauthenticated request', function (string $method, string $uri): void {
        $this->json($method, $uri)->assertUnauthorized();
    })->with([
        ['get', '/api/projects'],
        ['post', '/api/projects'],
    ]);

    it('registers a user and returns a usable token', function (): void {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Perry',
            'email' => 'perry@example.com',
            'password' => 'correct-horse-battery-staple',
        ])->assertCreated();

        $token = $response->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'perry@example.com');
    });

    it('does not reveal whether an email is registered', function (): void {
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/auth/login', [
            'email' => 'known@example.com', 'password' => 'wrong',
        ])->assertStatus(422);

        $unknown = $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com', 'password' => 'wrong',
        ])->assertStatus(422);

        expect($known->json('errors.email'))->toBe($unknown->json('errors.email'));
    });
});

describe('creating a project', function (): void {
    it('creates the project and dispatches the pipeline without crawling inline', function (): void {
        Bus::fake();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/projects', [
                'name' => 'Example Brokers',
                'sitemap_url' => 'https://example.com/sitemap.xml',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.progress.pages_crawled', 0);

        Bus::assertDispatched(ParseSitemapJob::class);
    });

    it('validates the input', function (array $payload, string $field): void {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/projects', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        'missing name' => [['sitemap_url' => 'https://example.com/sitemap.xml'], 'name'],
        'missing url' => [['name' => 'Example'], 'sitemap_url'],
        'not a url' => [['name' => 'Example', 'sitemap_url' => 'not a url'], 'sitemap_url'],
    ]);

    it('refuses a sitemap URL that points at a private address', function (): void {
        Bus::fake();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/projects', [
                'name' => 'Internal',
                'sitemap_url' => 'https://internal.test/sitemap.xml',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sitemap_url');

        Bus::assertNothingDispatched();
    });

    it('refuses a non-http scheme', function (): void {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/projects', [
                'name' => 'Local file',
                'sitemap_url' => 'file:///etc/passwd',
            ])
            ->assertStatus(422);
    });

    it('rate limits project creation', function (): void {
        Bus::fake();
        $user = User::factory()->create();
        $limit = (int) config('linkweaver.rate_limits.project_creation_per_user_hourly');

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($user)->postJson('/api/projects', [
                'name' => "Project {$i}",
                'sitemap_url' => 'https://example.com/sitemap.xml',
            ])->assertCreated();
        }

        $this->actingAs($user)->postJson('/api/projects', [
            'name' => 'One too many',
            'sitemap_url' => 'https://example.com/sitemap.xml',
        ])->assertStatus(429);
    });
});

describe('ownership', function (): void {
    it('lists only the signed-in user projects', function (): void {
        $user = User::factory()->create();
        Project::factory()->for($user)->create(['name' => 'Mine']);
        Project::factory()->create(['name' => 'Someone else']);

        $response = $this->actingAs($user)->getJson('/api/projects')->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.name'))->toBe('Mine');
    });

    it('hides another user project behind a 404 rather than a 403', function (): void {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/projects/{$project->id}")
            ->assertNotFound();
    });

    it('refuses to delete another user project', function (): void {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/projects/{$project->id}")
            ->assertNotFound();

        expect(Project::query()->whereKey($project->id)->exists())->toBeTrue();
    });

    it('deletes the owner own project and its pages', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $project->pages()->create([
            'url' => 'https://example.com/a/',
            'normalized_url' => 'https://example.com/a/',
        ]);

        $this->actingAs($user)->deleteJson("/api/projects/{$project->id}")->assertNoContent();

        expect(Project::query()->whereKey($project->id)->exists())->toBeFalse()
            ->and(DB::table('pages')->where('project_id', $project->id)->count())->toBe(0);
    });
});

describe('status polling', function (): void {
    it('returns a small payload shaped for the progress display', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->crawling()->create();

        $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/status")
            ->assertOk()
            ->assertExactJson([
                'status' => 'crawling',
                'status_label' => 'Crawling pages',
                'is_terminal' => false,
                'error_message' => null,
                'progress' => [
                    'pages_found' => 20,
                    'pages_crawled' => 7,
                    'pages_embedded' => 0,
                ],
            ]);
    });

    it('marks a finished project as terminal so the client stops polling', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->done()->create();

        $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/status")
            ->assertOk()
            ->assertJsonPath('is_terminal', true);
    });

    it('surfaces the failure reason', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)
            ->failed('The sitemap could not be read.')
            ->create();

        $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/status")
            ->assertOk()
            ->assertJsonPath('status', ProjectStatus::Failed->value)
            ->assertJsonPath('error_message', 'The sitemap could not be read.');
    });
});

describe('the demo project', function (): void {
    // The unauthenticated GET /demo endpoint arrives in phase 5 alongside the
    // seeder; what phase 1 guarantees is that the policy treats the demo as
    // readable by someone who does not own it.
    it('is readable by a user who does not own it', function (): void {
        $project = Project::factory()->demo()->done()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/projects/{$project->id}")
            ->assertOk();
    });

    it('cannot be deleted', function (): void {
        $project = Project::factory()->demo()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/projects/{$project->id}")
            ->assertNotFound();
    });
});
