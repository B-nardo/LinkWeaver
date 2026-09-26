<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table): void {
            $table->id();

            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_page_id')->constrained('pages')->cascadeOnDelete();

            // Null when the link points somewhere outside the sitemap: another
            // section of the site we did not crawl, or an external domain. The
            // raw target is always kept so the graph can still show it.
            $table->foreignId('target_page_id')->nullable()->constrained('pages')->cascadeOnDelete();
            $table->string('target_url', 2048);

            $table->text('anchor_text')->nullable();

            // Only in-content links count as real internal links during analysis.
            // Navigation, footer and sidebar links appear site-wide and would
            // make every page look well linked.
            $table->boolean('in_content')->default(true)->index();

            $table->timestamps();

            // Drives inbound-link counts, which is the hot path for orphan and
            // weak page detection.
            $table->index(['project_id', 'target_page_id', 'in_content']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
