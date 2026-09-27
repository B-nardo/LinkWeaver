<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SuggestionStatus;
use App\Models\Project;
use App\Models\Suggestion;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV a user takes away to actually do the work.
 *
 * Streamed and chunked rather than assembled in memory: this is the one
 * endpoint whose response grows with the size of the project, and the whole app
 * is built to run on a small free-tier container.
 *
 * Only approved suggestions are exported. The export is a worklist, not a
 * record of everything the tool considered.
 */
final class SuggestionExportController extends Controller
{
    private const array COLUMNS = [
        'source_url',
        'source_title',
        'target_url',
        'target_title',
        'anchor_text',
        'context_sentence',
        'similarity',
        'priority_score',
    ];

    public function __invoke(Project $project): StreamedResponse
    {
        abort_unless(Gate::allows('view', $project), Response::HTTP_NOT_FOUND);

        $filename = 'linkweaver-'.$project->id.'.csv';

        return response()->streamDownload(function () use ($project): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // Excel reads a bare UTF-8 CSV as the system codepage and mangles
            // anything non-ASCII; the BOM is what makes it read it correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, self::COLUMNS);

            Suggestion::query()
                ->with(['sourcePage', 'targetPage'])
                ->where('project_id', $project->id)
                ->where('status', SuggestionStatus::Approved)
                ->whereNotNull('anchor_text')
                ->orderByDesc('priority_score')
                ->chunkById(200, function ($suggestions) use ($handle): void {
                    foreach ($suggestions as $suggestion) {
                        fputcsv($handle, [
                            $suggestion->sourcePage?->normalized_url,
                            $suggestion->sourcePage?->title,
                            $suggestion->targetPage?->normalized_url,
                            $suggestion->targetPage?->title,
                            $suggestion->anchor_text,
                            $suggestion->context_sentence,
                            round((float) $suggestion->similarity, 4),
                            round((float) $suggestion->priority_score, 4),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
