<?php

declare(strict_types=1);

it('reports healthy when the database is reachable', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.ok', true)
        ->assertJsonPath('checks.queue.pending', 0);
});

it('exposes the framework health check used by uptime monitors', function (): void {
    $this->get('/up')->assertOk();
});

it('does not serve a web UI from the API host', function (): void {
    $this->get('/')->assertNotFound();
});
