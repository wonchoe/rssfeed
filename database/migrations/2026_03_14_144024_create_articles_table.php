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
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable()->index();
            $table->string('canonical_url_hash', 64)->unique();
            $table->text('canonical_url');
            $table->char('content_hash', 64)->index();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('discovered_at')->nullable()->index();
            $table->json('normalized_payload')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'published_at']);
            $table->index(['source_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
