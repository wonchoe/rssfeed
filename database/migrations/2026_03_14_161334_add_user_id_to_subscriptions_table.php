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
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            $table->dropUnique('subscriptions_unique_source_channel_target');
            $table->unique(
                ['user_id', 'source_id', 'channel', 'target_hash'],
                'subscriptions_unique_user_source_channel_target'
            );
            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropUnique('subscriptions_unique_user_source_channel_target');
            $table->dropIndex('subscriptions_user_id_is_active_index');
            $table->unique(
                ['source_id', 'channel', 'target_hash'],
                'subscriptions_unique_source_channel_target'
            );

            $table->dropConstrainedForeignId('user_id');
        });
    }
};
