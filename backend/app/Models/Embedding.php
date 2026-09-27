<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmbeddingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A page's embedding vector, cached against the content that produced it.
 *
 * The `(page_id, model, content_hash)` key is what keeps the project inside a
 * free Gemini quota: re-running a project only spends tokens on pages whose
 * content actually changed, and switching models re-embeds everything rather
 * than silently comparing vectors from two different models.
 *
 * Vectors are stored already normalised to unit length, so similarity is a dot
 * product rather than a cosine (see SimilarityCalculator).
 */
final class Embedding extends Model
{
    /** @use HasFactory<EmbeddingFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vector' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @param  Builder<Embedding>  $query
     */
    public function scopeForModel(Builder $query, string $model): void
    {
        $query->where('model', $model);
    }
}
