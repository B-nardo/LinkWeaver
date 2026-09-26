<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Health probe for uptime monitors and container orchestration.
 *
 * Reports queue depth alongside database reachability because the pipeline is
 * queue-driven: an API that answers while no worker is draining jobs is only
 * half healthy, and cold-start hosting makes that a real failure mode.
 */
final class HealthController extends Controller
{
    /**
     * A job waiting longer than this implies nothing is draining the queue.
     * Generous enough to tolerate a slow crawl and a cold-starting container.
     */
    private const int STALE_QUEUE_SECONDS = 300;

    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();
        $queue = $this->checkQueue($database['ok']);

        $healthy = $database['ok'] && $queue['worker_running'] !== false;

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => [
                'database' => $database,
                'queue' => $queue,
            ],
        ], $healthy ? 200 : 503);
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'unreachable'];
        }
    }

    /**
     * Queue depth plus whether anything is draining it.
     *
     * The whole pipeline is queued jobs, so an API that answers while no worker
     * is running is the product's real silent failure: projects sit at `pending`
     * forever while the frontend politely polls a status that never changes.
     * A backlog older than the threshold is the observable symptom.
     *
     * @return array{pending: int|null, oldest_pending_seconds: int|null, worker_running: bool|null}
     */
    private function checkQueue(bool $databaseIsUp): array
    {
        if (! $databaseIsUp) {
            return ['pending' => null, 'oldest_pending_seconds' => null, 'worker_running' => null];
        }

        try {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('available_at');

            $age = $oldest === null ? null : max(0, time() - (int) $oldest);

            return [
                'pending' => $pending,
                'oldest_pending_seconds' => $age,
                // An empty queue proves nothing either way, so it is not a
                // failure — only a stale backlog is.
                'worker_running' => $pending === 0 ? null : $age < self::STALE_QUEUE_SECONDS,
            ];
        } catch (Throwable) {
            return ['pending' => null, 'oldest_pending_seconds' => null, 'worker_running' => null];
        }
    }
}
