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
        Schema::create('parse_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parser_schema_id')->nullable()->constrained('parser_schemas')->nullOnDelete();
            $table->string('stage', 64)->index();
            $table->string('status', 32)->default('running')->index();
            $table->unsignedSmallInteger('http_status')->nullable()->index();
            $table->string('error_type', 64)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->boolean('used_browser')->default(false);
            $table->boolean('used_ai')->default(false);
            $table->string('snapshot_reference', 191)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->index(['source_id', 'stage', 'started_at']);
            $table->index(['source_id', 'status', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parse_attempts');
    }
};
