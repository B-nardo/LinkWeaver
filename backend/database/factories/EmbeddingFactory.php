<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Embedding;
use App\Models\Page;
use App\Services\Analysis\SimilarityCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Embedding>
 */
final class EmbeddingFactory extends Factory
{
    protected $model = Embedding::class;

    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            'model' => 'gemini-embedding-2',
            'vector' => SimilarityCalculator::normalize([1.0, 0.0, 0.0]),
            'content_hash' => hash('sha256', fake()->sentence()),
        ];
    }

    /**
     * Stores the given vector normalised, exactly as the job does, so tests
     * exercise the same representation production uses.
     *
     * @param  list<float>  $vector
     */
    public function withVector(array $vector): self
    {
        return $this->state(fn (): array => [
            'vector' => SimilarityCalculator::normalize($vector),
        ]);
    }

    public function forPage(Page $page): self
    {
        return $this->state(fn (): array => [
            'page_id' => $page->id,
            'content_hash' => $page->content_hash,
        ]);
    }
}
