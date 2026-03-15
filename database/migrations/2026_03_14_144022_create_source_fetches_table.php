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
        Schema::create('source_fetches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('fetched_url_hash', 64)->index();
            $table->text('fetched_url');
            $table->unsignedSmallInteger('http_status')->nullable()->index();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->char('content_hash', 64)->nullable()->index();
            $table->unsignedBigInteger('payload_bytes')->nullable();
            $table->timestamp('fetched_at')->index();
            $table->json('request_context')->nullable();
            $table->json('response_headers')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'fetched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_fetches');
    }
};
