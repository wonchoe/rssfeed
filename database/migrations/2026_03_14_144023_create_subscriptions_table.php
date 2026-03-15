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
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32)->index();
            $table->string('target_hash', 64)->index();
            $table->string('target', 512);
            $table->boolean('is_active')->default(true)->index();
            $table->json('config')->nullable();
            $table->timestamp('last_delivered_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['source_id', 'channel', 'target_hash'], 'subscriptions_unique_source_channel_target');
            $table->index(['source_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
