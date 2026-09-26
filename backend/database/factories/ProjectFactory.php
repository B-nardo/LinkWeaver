<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'sitemap_url' => 'https://example.com/sitemap.xml',
            'status' => ProjectStatus::Pending,
            'pages_found' => 0,
            'pages_crawled' => 0,
            'pages_embedded' => 0,
            'skip_taxonomies' => true,
            'is_demo' => false,
        ];
    }

    public function crawling(): self
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::Crawling,
            'pages_found' => 20,
            'pages_crawled' => 7,
        ]);
    }

    public function done(): self
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::Done,
            'pages_found' => 20,
            'pages_crawled' => 20,
        ]);
    }

    public function failed(string $message = 'The sitemap could not be read.'): self
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::Failed,
            'error_message' => $message,
        ]);
    }

    public function demo(): self
    {
        return $this->state(fn (): array => ['is_demo' => true]);
    }
}
