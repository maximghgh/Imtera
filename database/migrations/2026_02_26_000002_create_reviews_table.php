<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_source_id')->constrained('review_sources')->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('author_name')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->text('body')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['review_source_id', 'published_at']);
            $table->unique(['review_source_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
