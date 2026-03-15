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
        Schema::create('telegram_chats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('telegram_chat_id', 64)->unique();
            $table->string('chat_type', 32)->index();
            $table->string('title', 255)->nullable();
            $table->string('username', 255)->nullable();
            $table->boolean('is_forum')->default(false);
            $table->string('bot_membership_status', 32)->nullable()->index();
            $table->boolean('bot_is_member')->default(false)->index();
            $table->string('added_by_telegram_user_id', 64)->nullable()->index();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'bot_is_member']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_chats');
    }
};
