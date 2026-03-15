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
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->string('source_url_hash', 64)->unique();
            $table->text('source_url');
            $table->string('canonical_url_hash', 64)->nullable()->index();
            $table->text('canonical_url')->nullable();
            $table->string('source_type', 32)->default('unknown')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('polling_interval_minutes')->default(30);
            $table->timestamp('last_fetched_at')->nullable()->index();
            $table->timestamp('last_parsed_at')->nullable()->index();
            $table->char('latest_content_hash', 64)->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
