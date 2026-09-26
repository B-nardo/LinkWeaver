<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Deliberately tiny. The frontend polls this every couple of seconds while a
 * project is running, so it returns only what the progress display changes on —
 * no relations, no page list, no counts that require scanning tables.
 */
final class ProjectStatusController extends Controller
{
    public function __invoke(Request $request, Project $project): JsonResponse
    {
        abort_unless(Gate::allows('view', $project), Response::HTTP_NOT_FOUND);

        return response()->json([
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'is_terminal' => $project->status->isTerminal(),
            'error_message' => $project->error_message,
            'progress' => [
                'pages_found' => $project->pages_found,
                'pages_crawled' => $project->pages_crawled,
                'pages_embedded' => $project->pages_embedded,
            ],
        ]);
    }
}
