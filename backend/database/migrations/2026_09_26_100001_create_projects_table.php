<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            // UUID primary key: project ids appear in public URLs, so they must
            // not be enumerable. Child tables keep cheap sequential keys because
            // they are never addressed publicly.
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('sitemap_url', 2048);

            // Stored as a string rather than a MySQL ENUM so adding a pipeline
            // stage later is a code change, not a table rebuild.
            $table->string('status', 20)->default('pending')->index();

            $table->unsignedInteger('pages_found')->default(0);
            $table->unsignedInteger('pages_crawled')->default(0);
            $table->unsignedInteger('pages_embedded')->default(0);

            $table->text('error_message')->nullable();

            // Per-project override of the config default (spec 5.1): taxonomy
            // sitemaps are aggregations, not content, and skew the link graph.
            $table->boolean('skip_taxonomies')->default(true);

            $table->boolean('is_demo')->default(false)->index();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
