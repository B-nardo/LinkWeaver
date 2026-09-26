<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link from one page to another.
 *
 * `target_page_id` is null when the link points at a same-site URL that the
 * sitemap never listed, which is itself worth knowing: it means the site links
 * to pages it does not publish in its sitemap.
 */
final class Link extends Model
{
    /** @use HasFactory<LinkFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'in_content' => 'boolean',
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
     * The only links that count as real internal links during analysis.
     *
     * @param  Builder<Link>  $query
     */
    public function scopeEditorial(Builder $query): void
    {
        $query->where('in_content', true)->whereNotNull('target_page_id');
    }
}
