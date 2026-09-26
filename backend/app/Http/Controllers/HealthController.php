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
    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();

        return response()->json([
            'status' => $database['ok'] ? 'ok' : 'degraded',
            'checks' => [
                'database' => $database,
                'queue' => $this->checkQueue($database['ok']),
            ],
        ], $database['ok'] ? 200 : 503);
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
     * @return array{pending: int|null}
     */
    private function checkQueue(bool $databaseIsUp): array
    {
        if (! $databaseIsUp) {
            return ['pending' => null];
        }

        try {
            return ['pending' => DB::table('jobs')->count()];
        } catch (Throwable) {
            return ['pending' => null];
        }
    }
}
