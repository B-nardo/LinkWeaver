<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed internal link, created in two stages.
 *
 * Phase 3 writes the candidate: a source and target that are topically related
 * but not yet linked, with a similarity and a priority score. The anchor text
 * and its context sentence stay null until phase 4 asks the text model for
 * them and validates the answer against the source page's actual content.
 */
final class Suggestion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'similarity' => 'float',
            'priority_score' => 'float',
            'status' => SuggestionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'source_page_id');
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function targetPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'target_page_id');
    }

    /**
     * Candidates still waiting for an anchor from the text model.
     *
     * @param  Builder<Suggestion>  $query
     */
    public function scopeAwaitingAnchor(Builder $query): void
    {
        $query->whereNull('anchor_text');
    }
}
