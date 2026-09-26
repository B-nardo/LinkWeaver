<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('reports healthy when the database is reachable', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.ok', true)
        ->assertJsonPath('checks.queue.pending', 0);
});

it('does not judge the worker when the queue is empty', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('checks.queue.worker_running', null);
});

it('reports degraded when jobs are backing up unprocessed', function (): void {
    // A job queued well beyond the staleness threshold: the observable symptom
    // of an API running with no queue worker behind it.
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.queue.worker_running', false);
});

it('exposes the framework health check used by uptime monitors', function (): void {
    $this->get('/up')->assertOk();
});

it('does not serve a web UI from the API host', function (): void {
    $this->get('/')->assertNotFound();
});
