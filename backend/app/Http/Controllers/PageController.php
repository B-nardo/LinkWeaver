<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexPagesRequest;
use App\Http\Resources\PageResource;
use App\Models\Project;
use App\Services\Analysis\PageLinkQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class PageController extends Controller
{
    public function index(
        IndexPagesRequest $request,
        Project $project,
        PageLinkQuery $pages,
    ): AnonymousResourceCollection {
        abort_unless(Gate::allows('view', $project), Response::HTTP_NOT_FOUND);

        $query = $pages->filtered(
            $project,
            $request->filter(),
            $request->sort(),
            $request->direction(),
        );

        return PageResource::collection(
            $query->paginate($request->perPage())->withQueryString()
        );
    }
}
