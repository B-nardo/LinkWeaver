<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Analysis\CandidateGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Turns embeddings into scored link candidates (spec 5.6), the last stage of
 * the pipeline.
 *
 * Runs no network calls of its own: everything it needs is already in the
 * database, which is why it is cheap to re-run after changing a threshold or a
 * weighting.
 */
final class AnalyzeProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly Project $project) {}

    public function handle(CandidateGenerator $candidates): void
    {
        $this->project->transitionTo(ProjectStatus::Analyzing);

        $candidates->generate($this->project);

        // Anchors are the last stage: it decides for itself whether Gemini is
        // configured, and completes the project either way.
        SuggestAnchorsJob::dispatch($this->project);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        $this->project->markFailed(
            'The crawl finished, but link analysis could not be completed.'
        );
    }
}
