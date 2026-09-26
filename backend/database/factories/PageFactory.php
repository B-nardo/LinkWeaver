<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Page;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
final class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        $slug = fake()->unique()->slug();
        $url = "https://example.com/{$slug}/";
        $text = fake()->paragraphs(4, true);

        return [
            'project_id' => Project::factory(),
            'url' => $url,
            'normalized_url' => $url,
            'title' => fake()->sentence(),
            'h1' => fake()->sentence(),
            'meta_description' => fake()->sentence(12),
            'content_text' => $text,
            'content_hash' => hash('sha256', $text),
            'word_count' => str_word_count($text),
            'http_status' => 200,
            'crawled_at' => now(),
        ];
    }

    public function at(string $url): self
    {
        return $this->state(fn (): array => [
            'url' => $url,
            'normalized_url' => $url,
        ]);
    }

    public function uncrawled(): self
    {
        return $this->state(fn (): array => [
            'title' => null,
            'h1' => null,
            'meta_description' => null,
            'content_text' => null,
            'content_hash' => null,
            'word_count' => 0,
            'http_status' => null,
            'crawled_at' => null,
        ]);
    }

    public function failed(string $error = 'Connection timed out.'): self
    {
        return $this->uncrawled()->state(fn (): array => [
            'crawl_error' => $error,
            'crawled_at' => now(),
        ]);
    }
}
