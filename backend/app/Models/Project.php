<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An audit of one website.
 *
 * The UUID primary key is deliberate: project ids appear in public URLs, so
 * sequential ids would let anyone enumerate how many projects exist and probe
 * for others'.
 *
 * @property string $id
 * @property ProjectStatus $status
 */
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'name',
        'sitemap_url',
        'skip_taxonomies',
    ];

    /**
     * Mirrors the column defaults so a newly created model reports the same
     * state in memory as it holds in the database. Without this, `status` reads
     * as null until the row is reloaded.
     */
    protected $attributes = [
        'status' => ProjectStatus::Pending->value,
        'pages_found' => 0,
        'pages_crawled' => 0,
        'pages_embedded' => 0,
        'skip_taxonomies' => true,
        'is_demo' => false,
    ];

    /**
     * Counters and status are advanced by the pipeline, never by mass
     * assignment from a request.
     */
    protected $guarded = [
        'id',
        'user_id',
        'status',
        'pages_found',
        'pages_crawled',
        'pages_embedded',
        'error_message',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'skip_taxonomies' => 'boolean',
            'is_demo' => 'boolean',
            'pages_found' => 'integer',
            'pages_crawled' => 'integer',
            'pages_embedded' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * @return HasMany<Link, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => ProjectStatus::Failed,
            // Truncated because the column is TEXT and the message may carry a
            // long upstream exception chain.
            'error_message' => mb_substr($message, 0, 2000),
        ])->save();
    }

    public function transitionTo(ProjectStatus $status): void
    {
        $this->forceFill(['status' => $status])->save();
    }
}
