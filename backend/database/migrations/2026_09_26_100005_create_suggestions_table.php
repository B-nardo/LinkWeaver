<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggestions', function (Blueprint $table): void {
            $table->id();

            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('target_page_id')->constrained('pages')->cascadeOnDelete();

            $table->float('similarity');
            $table->float('priority_score');

            // Both are validated model output: the anchor must appear verbatim in
            // the source page's content, outside any existing link or heading.
            $table->string('anchor_text');
            $table->text('context_sentence');

            $table->string('status', 20)->default('pending');

            $table->timestamps();

            // The review screen sorts by score within a status filter.
            $table->index(['project_id', 'status', 'priority_score']);

            // A source should never be offered the same target twice.
            $table->unique(['source_page_id', 'target_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
