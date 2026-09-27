<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoProjectSeeder;

/*
|------------------------------------------------------------------------------
| The public demo
|------------------------------------------------------------------------------
|
| Spec 6 requires the demo to be readable with no account, which meant moving
| the read-only project routes out of the auth middleware group. That widens the
| unauthenticated surface, so these tests exist to pin down exactly how far:
| a guest may read the demo and nothing else.
|
*/

describe('the demo endpoint', function (): void {
    it('points a visitor at the seeded demo project without an account', function (): void {
        $this->seed(DemoProjectSeeder::class);

        $this->getJson('/api/demo')
            ->assertOk()
            ->assertJsonPath('data.is_demo', true)
            ->assertJsonPath('data.status', 'done');
    });

    it('says so plainly when no demo has been seeded', function (): void {
        $this->getJson('/api/demo')->assertNotFound();
    });
});

describe('what a guest may read', function (): void {
    beforeEach(function (): void {
        $this->seed(DemoProjectSeeder::class);
        $this->demo = Project::query()->where('is_demo', true)->sole();
    });

    it('allows the demo project, graph, pages and suggestions', function (string $suffix): void {
        $this->getJson("/api/projects/{$this->demo->id}{$suffix}")->assertOk();
    })->with(['', '/graph', '/pages', '/suggestions', '/status']);

    it('allows the demo CSV export', function (): void {
        $this->get("/api/projects/{$this->demo->id}/export.csv")->assertOk();
    });

    it('returns a graph a visitor can actually look at', function (): void {
        $response = $this->getJson("/api/projects/{$this->demo->id}/graph")->assertOk();

        expect($response->json('nodes'))->toHaveCount(15)
            ->and($response->json('summary.orphans'))->toBeGreaterThan(0)
            ->and($response->json('edges'))->not->toBeEmpty();
    });

    it('returns suggestions whose anchors really are on the source page', function (): void {
        $response = $this->getJson("/api/projects/{$this->demo->id}/suggestions")->assertOk();

        expect($response->json('data'))->not->toBeEmpty();

        foreach ($response->json('data') as $suggestion) {
            $source = App\Models\Page::query()->find($suggestion['source']['id']);

            // The demo must not contradict the product's central claim.
            expect(stripos((string) $source?->content_text, $suggestion['anchor_text']))
                ->not->toBeFalse();
        }
    });
});

describe('what a guest may not do', function (): void {
    it('cannot read a real project', function (string $suffix): void {
        $project = Project::factory()->done()->create();

        $this->getJson("/api/projects/{$project->id}{$suffix}")->assertNotFound();
    })->with(['', '/graph', '/pages', '/suggestions', '/status']);

    it('cannot export a real project', function (): void {
        $project = Project::factory()->done()->create();

        $this->get("/api/projects/{$project->id}/export.csv")->assertNotFound();
    });

    it('cannot list projects', function (): void {
        $this->getJson('/api/projects')->assertUnauthorized();
    });

    it('cannot create a project', function (): void {
        $this->postJson('/api/projects', [
            'name' => 'Mine', 'sitemap_url' => 'https://example.com/sitemap.xml',
        ])->assertUnauthorized();
    });

    it('cannot delete the demo', function (): void {
        $this->seed(DemoProjectSeeder::class);
        $demo = Project::query()->where('is_demo', true)->sole();

        $this->deleteJson("/api/projects/{$demo->id}")->assertUnauthorized();
    });

    it('cannot approve or reject a demo suggestion', function (): void {
        $this->seed(DemoProjectSeeder::class);
        $demo = Project::query()->where('is_demo', true)->sole();
        $id = App\Models\Suggestion::query()->where('project_id', $demo->id)->value('id');

        $this->patchJson("/api/suggestions/{$id}", ['status' => 'approved'])
            ->assertUnauthorized();
    });
});

describe('a signed-in user is unaffected', function (): void {
    it('still sees their own project', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->done()->create();

        $this->actingAs($user)->getJson("/api/projects/{$project->id}")->assertOk();
    });

    it('still cannot see somebody else project', function (): void {
        $project = Project::factory()->done()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/projects/{$project->id}")
            ->assertNotFound();
    });

    it('still cannot modify a demo suggestion', function (): void {
        $this->seed(DemoProjectSeeder::class);
        $demo = Project::query()->where('is_demo', true)->sole();
        $id = App\Models\Suggestion::query()->where('project_id', $demo->id)->value('id');

        // ProjectPolicy::update excludes demo projects for everyone.
        $this->actingAs(User::factory()->create())
            ->patchJson("/api/suggestions/{$id}", ['status' => 'approved'])
            ->assertNotFound();
    });
});

describe('the seeder', function (): void {
    it('can run twice without duplicating the demo', function (): void {
        $this->seed(DemoProjectSeeder::class);
        $this->seed(DemoProjectSeeder::class);

        expect(Project::query()->where('is_demo', true)->count())->toBe(1);
    });

    it('produces a project that needs no crawl and no Gemini call', function (): void {
        $this->seed(DemoProjectSeeder::class);
        $demo = Project::query()->where('is_demo', true)->sole();

        expect($demo->status->value)->toBe('done')
            ->and($demo->pages()->whereNull('content_text')->count())->toBe(0)
            ->and($demo->pages()->whereNotNull('crawl_error')->count())->toBe(0);
    });
});

describe('token authentication on the public routes', function (): void {
    /*
    | These use a real bearer token rather than actingAs().
    |
    | actingAs() sets the user directly on the guard, which bypasses the
    | middleware that resolves a Sanctum token. That is exactly how a real bug
    | shipped: when these routes were moved out of `auth:sanctum`, nothing
    | resolved the token any more, so every signed-in user was treated as a
    | guest and got 404 on their own project — while the actingAs() tests went
    | on passing.
    |
    | Anything asserting who a request is authenticated as on these routes has
    | to go through the token.
    */

    function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    it('lets an owner read their own project with a bearer token', function (string $suffix): void {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->done()->create();

        $this->withHeader('Authorization', 'Bearer '.tokenFor($user))
            ->getJson("/api/projects/{$project->id}{$suffix}")
            ->assertOk();
    })->with(['', '/graph', '/pages', '/suggestions', '/status']);

    it('lets an owner export their own project with a bearer token', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->done()->create();

        $this->withHeader('Authorization', 'Bearer '.tokenFor($user))
            ->get("/api/projects/{$project->id}/export.csv")
            ->assertOk();
    });

    it('still hides another user project from a valid token', function (): void {
        $project = Project::factory()->done()->create();

        $this->withHeader('Authorization', 'Bearer '.tokenFor(User::factory()->create()))
            ->getJson("/api/projects/{$project->id}")
            ->assertNotFound();
    });

    it('treats a garbage token as a guest rather than erroring', function (): void {
        $project = Project::factory()->done()->create();

        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson("/api/projects/{$project->id}")
            ->assertNotFound();
    });

    it('still serves the demo to a signed-in user', function (): void {
        $this->seed(DemoProjectSeeder::class);
        $demo = Project::query()->where('is_demo', true)->sole();

        $this->withHeader('Authorization', 'Bearer '.tokenFor(User::factory()->create()))
            ->getJson("/api/projects/{$demo->id}/graph")
            ->assertOk();
    });
});
