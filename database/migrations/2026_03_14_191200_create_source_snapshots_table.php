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
        Schema::create('source_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parse_attempt_id')->nullable()->constrained('parse_attempts')->nullOnDelete();
            $table->string('snapshot_kind', 32)->default('payload')->index();
            $table->longText('html_snapshot')->nullable();
            $table->json('headers')->nullable();
            $table->text('final_url')->nullable();
            $table->char('content_hash', 64)->nullable()->index();
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->index(['source_id', 'captured_at']);
            $table->index(['source_id', 'snapshot_kind', 'captured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_snapshots');
    }
};
