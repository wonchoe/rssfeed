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
        Schema::create('parser_schemas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('strategy_type', 64)->default('deterministic_feed')->index();
            $table->json('schema_payload')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->decimal('validation_score', 5, 2)->nullable();
            $table->string('created_by', 32)->default('rule_based')->index();
            $table->boolean('is_active')->default(false)->index();
            $table->boolean('is_shadow')->default(false)->index();
            $table->timestamp('last_validated_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['source_id', 'version']);
            $table->index(['source_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_schemas');
    }
};
