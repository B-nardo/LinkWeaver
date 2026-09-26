<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embeddings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('page_id')->constrained()->cascadeOnDelete();

            $table->string('model');
            $table->json('vector');

            // Cache key: an embedding is reusable only while both the content and
            // the model that produced it are unchanged.
            $table->char('content_hash', 64);

            $table->timestamps();

            $table->unique(['page_id', 'model', 'content_hash']);
            $table->index(['model', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};
