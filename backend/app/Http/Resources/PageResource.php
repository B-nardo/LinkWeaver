<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\PageClassification;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Page
 */
final class PageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Counts arrive from a joined aggregate, which PDO may hand back as
        // strings depending on driver settings. Cast once, here, so the API
        // contract is numeric regardless.
        $inbound = (int) ($this->inbound_count ?? 0);
        $outbound = (int) ($this->outbound_count ?? 0);

        $classification = PageClassification::fromInboundCount(
            $inbound,
            (int) config('linkweaver.analysis.weak_inbound_threshold')
        );

        return [
            'id' => $this->id,
            'url' => $this->url,
            'normalized_url' => $this->normalized_url,
            'title' => $this->title,
            'h1' => $this->h1,
            'word_count' => (int) $this->word_count,
            'http_status' => $this->http_status,
            'crawl_error' => $this->crawl_error,
            'crawled_at' => $this->crawled_at?->toIso8601String(),
            'inbound_count' => $inbound,
            'outbound_count' => $outbound,
            'classification' => $classification->value,
            'classification_label' => $classification->label(),
        ];
    }
}
