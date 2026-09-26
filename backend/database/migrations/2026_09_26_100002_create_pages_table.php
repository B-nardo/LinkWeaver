<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();

            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();

            // `url` keeps the address exactly as the sitemap gave it, for display
            // and for re-crawling. `normalized_url` is the canonical form every
            // comparison uses, produced by UrlNormalizer.
            $table->string('url', 2048);
            $table->string('normalized_url', 500);

            $table->string('title')->nullable();
            $table->string('h1')->nullable();
            $table->text('meta_description')->nullable();

            $table->longText('content_text')->nullable();

            // SHA-256 of the clean content. Embeddings are cached against this,
            // so unchanged pages are never re-embedded.
            $table->char('content_hash', 64)->nullable()->index();

            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('crawl_error')->nullable();
            $table->timestamp('crawled_at')->nullable();

            $table->timestamps();

            // The uniqueness guarantee the whole crawl depends on: one row per
            // canonical URL per project. normalized_url is capped at 500 chars
            // to stay inside InnoDB's 3072-byte key limit under utf8mb4
            // (36*4 + 500*4 = 2144 bytes); longer URLs are skipped at parse time.
            $table->unique(['project_id', 'normalized_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
