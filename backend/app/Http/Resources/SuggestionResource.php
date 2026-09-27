<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Suggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Suggestion
 */
final class SuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'anchor_text' => $this->anchor_text,
            'context_sentence' => $this->context_sentence,
            'similarity' => round((float) $this->similarity, 4),
            'priority_score' => round((float) $this->priority_score, 4),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // Both pages inline: the review screen shows them side by side, and
            // a second request per row would make the table crawl.
            'source' => $this->pageSummary($this->sourcePage),
            'target' => $this->pageSummary($this->targetPage),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pageSummary(mixed $page): ?array
    {
        if ($page === null) {
            return null;
        }

        return [
            'id' => $page->id,
            'title' => $page->title,
            'url' => $page->normalized_url,
        ];
    }
}
