<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_connections', function (Blueprint $table): void {
            $table->id();

            // One WordPress site per project.
            $table->foreignUuid('project_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('site_url', 2048);
            $table->string('username');

            // WordPress application password, written through an encrypted cast.
            // Ciphertext is far longer than the 24-character plaintext, hence text.
            $table->text('app_password');

            $table->timestamp('last_verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_connections');
    }
};
