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
        Schema::create('translated_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 10)->index();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('content_html');
            $table->string('source_name')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('original_url', 2048);
            $table->string('image_url', 2048)->nullable();
            $table->timestamp('translated_at')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translated_articles');
    }
};
