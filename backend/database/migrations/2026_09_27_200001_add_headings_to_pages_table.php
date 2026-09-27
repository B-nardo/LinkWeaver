<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Headings found in a page's main content.
 *
 * Spec 5.7 requires rejecting a suggested anchor that already sits inside a
 * link or a heading. Links are covered by `links.anchor_text`, which the crawl
 * already stores, but headings were lost when the extractor flattened the page
 * to plain text.
 *
 * Storing the heading strings rather than the whole content HTML keeps this to
 * a few hundred bytes per page while still letting the anchor validator do an
 * exact check instead of a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->json('headings')->nullable()->after('h1');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn('headings');
        });
    }
};
