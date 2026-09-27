<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * Points a visitor at the seeded demo project (spec 6).
 *
 * Exists so the landing page does not have to know the demo's UUID: it asks
 * for "the demo" and gets whichever project the seeder most recently built.
 */
final class DemoController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $project = Project::query()->where('is_demo', true)->latest()->first();

        if ($project === null) {
            // A container that has not been seeded should say so plainly rather
            // than show a visitor a broken screen.
            return response()->json([
                'message' => 'No demo project has been seeded on this server.',
            ], 404);
        }

        return ProjectResource::make($project)->response();
    }
}
