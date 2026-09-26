<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
final class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sitemap_url' => $this->sitemap_url,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_terminal' => $this->status->isTerminal(),
            'skip_taxonomies' => $this->skip_taxonomies,
            'is_demo' => $this->is_demo,
            'error_message' => $this->error_message,
            'progress' => [
                'pages_found' => $this->pages_found,
                'pages_crawled' => $this->pages_crawled,
                'pages_embedded' => $this->pages_embedded,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
