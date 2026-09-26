<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Link;
use App\Models\Page;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Link>
 */
final class LinkFactory extends Factory
{
    protected $model = Link::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'source_page_id' => Page::factory(),
            'target_page_id' => Page::factory(),
            'target_url' => 'https://example.com/target/',
            'anchor_text' => fake()->words(3, true),
            'in_content' => true,
        ];
    }

    /**
     * Navigation or footer furniture: present in the DOM, but never counted as
     * a real internal link.
     */
    public function navigation(): self
    {
        return $this->state(fn (): array => ['in_content' => false]);
    }

    public function from(Page $source): self
    {
        return $this->state(fn (): array => [
            'project_id' => $source->project_id,
            'source_page_id' => $source->id,
        ]);
    }

    public function to(Page $target): self
    {
        return $this->state(fn (): array => [
            'target_page_id' => $target->id,
            'target_url' => $target->normalized_url,
        ]);
    }

    /**
     * A link to a same-site URL the sitemap never listed.
     */
    public function unresolved(): self
    {
        return $this->state(fn (): array => ['target_page_id' => null]);
    }
}
