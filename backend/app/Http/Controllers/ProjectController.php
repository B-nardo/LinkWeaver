<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Jobs\ParseSitemapJob;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Creating a project does no crawling. It validates, records, and dispatches —
 * so the response returns immediately and a slow site can never tie up a web
 * worker.
 */
final class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = $request->user()->projects()
            ->latest()
            ->paginate(20);

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $request->user()->projects()->create($request->validated());

        ParseSitemapJob::dispatch($project);

        return ProjectResource::make($project)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        // 404 rather than 403: confirming that a project id exists but belongs
        // to somebody else is information this endpoint has no reason to give.
        abort_unless(Gate::allows('view', $project), Response::HTTP_NOT_FOUND);

        return ProjectResource::make($project);
    }

    public function destroy(Request $request, Project $project): Response
    {
        abort_unless(Gate::allows('delete', $project), Response::HTTP_NOT_FOUND);

        // Pages, links and suggestions cascade at the database level.
        $project->delete();

        return response()->noContent();
    }
}
