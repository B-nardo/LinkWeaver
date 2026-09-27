<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One crawled URL.
 *
 * `normalized_url` is the identity of the page; `url` is only what the sitemap
 * happened to say. Everything that compares pages compares the normalised form.
 */
final class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'headings' => 'array',
            'crawled_at' => 'datetime',
            'word_count' => 'integer',
            'http_status' => 'integer',
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
     * Links this page points at.
     *
     * @return HasMany<Link, $this>
     */
    public function outboundLinks(): HasMany
    {
        return $this->hasMany(Link::class, 'source_page_id');
    }

    /**
     * Links pointing at this page. Inbound in-content links are what decide
     * whether a page is an orphan.
     *
     * @return HasMany<Link, $this>
     */
    public function inboundLinks(): HasMany
    {
        return $this->hasMany(Link::class, 'target_page_id');
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopeCrawled(Builder $query): void
    {
        $query->whereNotNull('crawled_at')->whereNull('crawl_error');
    }
}
