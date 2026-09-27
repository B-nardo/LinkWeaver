<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A suggestion is created in two stages.
 *
 * Phase 3 produces candidates: a source and target that are topically related
 * but not linked, with a similarity and a priority score. The anchor text and
 * its surrounding sentence do not exist until phase 4 asks the text model for
 * them and validates the answer against the source page.
 *
 * Making both nullable lets a candidate be stored as a pending suggestion
 * awaiting an anchor, rather than introducing a second table for what is the
 * same row at an earlier stage of its life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->string('anchor_text')->nullable()->change();
            $table->text('context_sentence')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->string('anchor_text')->nullable(false)->change();
            $table->text('context_sentence')->nullable(false)->change();
        });
    }
};
