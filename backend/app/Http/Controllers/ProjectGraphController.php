<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Analysis\LinkGraphBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Nodes and edges for the link graph, plus the summary the overview shows
 * alongside it.
 *
 * Not wrapped in a resource: this is a whole-project document rather than a
 * collection of records, and the graph component consumes it as one shape.
 */
final class ProjectGraphController extends Controller
{
    public function __invoke(Project $project, LinkGraphBuilder $graph): JsonResponse
    {
        abort_unless(Gate::allows('view', $project), Response::HTTP_NOT_FOUND);

        return response()->json($graph->build($project));
    }
}
