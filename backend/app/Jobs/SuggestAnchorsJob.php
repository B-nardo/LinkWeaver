<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Enums\SuggestionStatus;
use App\Models\Project;
use App\Models\Suggestion;
use App\Services\Anchors\AnchorPrompt;
use App\Services\Anchors\AnchorValidator;
use App\Services\Gemini\Exceptions\GeminiConfigurationException;
use App\Services\Gemini\Exceptions\GeminiRequestException;
use App\Services\Gemini\GeminiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Asks the text model for anchor text for the best candidates (spec 5.7).
 *
 * Two constraints shape this job.
 *
 * `generateContent` cannot be batched, so this costs one request per candidate.
 * A 200-page project can produce a thousand candidates, which on a free-tier
 * quota is over an hour of queue time — so only the highest-priority ones get
 * an anchor, capped by `anchors.per_project`.
 *
 * And model output is untrusted. Everything it returns goes through
 * AnchorValidator before it is stored, and a candidate that fails validation is
 * marked failed rather than deleted: that keeps it out of the review queue and
 * stops the job paying for the same rejection on every future run.
 */
final class SuggestAnchorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 900;

    public function __construct(public readonly Project $project) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Guards how often this job starts. It does not — and cannot — pace the
        // many requests the job then makes; see pace() below.
        return [(new RateLimited('gemini'))->dontRelease()];
    }

    /**
     * Keeps the configured request rate honest inside a single job.
     *
     * Unlike embeddings, generateContent cannot be batched, so one run makes
     * one request per candidate. Job middleware only sees the job, so without
     * this the job would issue its whole burst as fast as the network allows
     * and blow through a free-tier quota that the config claims to respect.
     */
    private function pace(): void
    {
        $perMinute = max(1, (int) config('linkweaver.gemini.requests_per_minute'));

        Sleep::for(60 / $perMinute)->seconds();
    }

    public function handle(GeminiClient $gemini, AnchorValidator $validator): void
    {
        $model = (string) config('linkweaver.gemini.text_model');
        $apiKey = (string) config('linkweaver.gemini.api_key');

        // As with embeddings: without Gemini the crawl and link analysis still
        // stand on their own, so finish cleanly rather than failing.
        if ($apiKey === '' || $model === '') {
            $this->project->transitionTo(ProjectStatus::Done);

            return;
        }

        try {
            $this->suggestFor($gemini, $validator, $model);
        } catch (GeminiRequestException|GeminiConfigurationException $e) {
            $this->project->markFailed($e->getMessage());

            return;
        }

        $this->project->transitionTo(ProjectStatus::Done);
    }

    private function suggestFor(GeminiClient $gemini, AnchorValidator $validator, string $model): void
    {
        $contextWords = (int) config('linkweaver.anchors.context_words');
        $temperature = (float) config('linkweaver.anchors.temperature');

        foreach ($this->candidates() as $suggestion) {
            $source = $suggestion->sourcePage;
            $target = $suggestion->targetPage;

            if ($source === null || $target === null || ($source->content_text ?? '') === '') {
                $this->reject($suggestion, 'The source page has no content to quote from.');

                continue;
            }

            $this->pace();

            $payload = $gemini->generateJson(
                AnchorPrompt::build(
                    (string) $source->content_text,
                    $target->title,
                    $target->meta_description,
                    $contextWords,
                    (int) config('linkweaver.anchors.min_words'),
                    (int) config('linkweaver.anchors.max_words'),
                ),
                $model,
                AnchorPrompt::schema(),
                $temperature,
            );

            $candidate = $validator->validate(
                $payload,
                (string) $source->content_text,
                $this->existingAnchorsFor((int) $source->id),
                is_array($source->headings) ? array_values($source->headings) : [],
            );

            if (! $candidate->isValid()) {
                $this->reject($suggestion, (string) $candidate->reason);

                continue;
            }

            $suggestion->forceFill([
                'anchor_text' => $candidate->anchor,
                'context_sentence' => $candidate->contextSentence,
                'status' => SuggestionStatus::Pending,
            ])->save();
        }
    }

    /**
     * The highest-priority candidates that have not been attempted yet.
     *
     * @return \Illuminate\Support\Collection<int, Suggestion>
     */
    private function candidates()
    {
        return Suggestion::query()
            ->with(['sourcePage', 'targetPage'])
            ->where('project_id', $this->project->id)
            ->whereNull('anchor_text')
            // Excludes anything already marked failed, so a rejection is paid
            // for once and never again.
            ->where('status', SuggestionStatus::Pending)
            ->orderByDesc('priority_score')
            ->limit(max(0, (int) config('linkweaver.anchors.per_project')))
            ->get();
    }

    /**
     * Anchor text of links the source page already carries, so the model
     * cannot propose wording that is part of an existing link.
     *
     * @return list<string>
     */
    private function existingAnchorsFor(int $sourcePageId): array
    {
        return DB::table('links')
            ->where('source_page_id', $sourcePageId)
            ->whereNotNull('anchor_text')
            ->pluck('anchor_text')
            ->filter(static fn (?string $text): bool => $text !== null && $text !== '')
            ->values()
            ->all();
    }

    private function reject(Suggestion $suggestion, string $reason): void
    {
        // Logged rather than discarded silently: a sudden rise in rejections is
        // how a prompt regression or a model change announces itself.
        Log::info('Anchor suggestion rejected', [
            'suggestion_id' => $suggestion->id,
            'project_id' => $this->project->id,
            'reason' => $reason,
        ]);

        $suggestion->forceFill(['status' => SuggestionStatus::Failed])->save();
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        $this->project->markFailed(
            'Anchor suggestions could not be generated: '.$exception->getMessage()
        );
    }
}
