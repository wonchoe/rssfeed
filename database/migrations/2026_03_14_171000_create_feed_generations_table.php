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
        Schema::create('feed_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->text('requested_url');
            $table->text('resolved_url')->nullable();
            $table->string('source_type', 32)->nullable()->index();
            $table->string('status', 32)->default('queued')->index();
            $table->string('message', 255)->nullable();
            $table->json('preview_items')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at'], 'feed_generations_user_status_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_generations');
    }
};
