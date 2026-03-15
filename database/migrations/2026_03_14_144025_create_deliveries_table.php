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
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32)->index();
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'subscription_id', 'channel'], 'deliveries_unique_article_subscription_channel');
            $table->index(['status', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
