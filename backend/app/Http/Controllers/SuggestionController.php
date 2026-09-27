<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SuggestionStatus;
use App\Http\Requests\BulkUpdateSuggestionsRequest;
use App\Http\Requests\IndexSuggestionsRequest;
use App\Http\Requests\UpdateSuggestionRequest;
use App\Http\Resources\SuggestionResource;
use App\Models\Project;
use App\Models\Suggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class SuggestionController extends Controller
{
    public function index(IndexSuggestionsRequest $request, Project $project): AnonymousResourceCollection
    {
        abort_unless(Gate::allows('view', $project), Response::HTTP_NOT_FOUND);

        $query = Suggestion::query()
            ->with(['sourcePage', 'targetPage'])
            ->where('project_id', $project->id)
            // A suggestion without an anchor is unfinished work, not something
            // anyone can review, so it never reaches the queue.
            ->whereNotNull('anchor_text')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('min_score'), fn ($q, $score) => $q->where('priority_score', '>=', (float) $score))
            ->when($request->query('source_page_id'), fn ($q, $id) => $q->where('source_page_id', (int) $id))
            ->when($request->query('target_page_id'), fn ($q, $id) => $q->where('target_page_id', (int) $id))
            ->orderByDesc('priority_score')
            ->orderBy('id');

        return SuggestionResource::collection(
            $query->paginate((int) ($request->query('per_page') ?? 25))->withQueryString()
        );
    }

    public function update(UpdateSuggestionRequest $request, Suggestion $suggestion): SuggestionResource
    {
        $this->authorizeSuggestion($suggestion);

        $suggestion->forceFill([
            'status' => SuggestionStatus::from($request->string('status')->toString()),
        ])->save();

        return SuggestionResource::make($suggestion->load(['sourcePage', 'targetPage']));
    }

    /**
     * Approve or reject many at once.
     *
     * Scoped to the project in the URL, so ids belonging to somebody else's
     * project are simply not matched rather than rejected with an error that
     * would confirm they exist.
     */
    public function bulk(BulkUpdateSuggestionsRequest $request, Project $project): JsonResponse
    {
        abort_unless(Gate::allows('view', $project), Response::HTTP_NOT_FOUND);

        $updated = Suggestion::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $request->array('ids'))
            ->whereNotNull('anchor_text')
            ->update(['status' => $request->string('status')->toString()]);

        return response()->json(['updated' => $updated]);
    }

    private function authorizeSuggestion(Suggestion $suggestion): void
    {
        $project = $suggestion->project;

        abort_unless(
            $project !== null && Gate::allows('update', $project),
            Response::HTTP_NOT_FOUND
        );
    }
}
