<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stages of the pipeline, in the order a project passes through them.
 *
 * The frontend polls this to drive the progress display, so the values are part
 * of the API contract and must not be renamed casually.
 */
enum ProjectStatus: string
{
    case Pending = 'pending';
    case Crawling = 'crawling';
    case Embedding = 'embedding';
    case Analyzing = 'analyzing';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * Whether the pipeline has stopped. The frontend stops polling on these.
     */
    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Crawling => 'Crawling pages',
            self::Embedding => 'Generating embeddings',
            self::Analyzing => 'Analysing links',
            self::Done => 'Complete',
            self::Failed => 'Failed',
        };
    }
}
