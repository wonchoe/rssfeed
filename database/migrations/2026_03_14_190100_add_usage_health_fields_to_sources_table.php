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
        Schema::table('sources', function (Blueprint $table): void {
            $table->string('domain', 255)->nullable()->after('canonical_url')->index();
            $table->string('host', 255)->nullable()->after('domain')->index();
            $table->string('usage_state', 24)->default('inactive')->after('status')->index();
            $table->unsignedTinyInteger('health_score')->default(50)->after('usage_state')->index();
            $table->string('health_state', 24)->default('unknown')->after('health_score')->index();
            $table->unsignedInteger('consecutive_failures')->default(0)->after('health_state');
            $table->timestamp('last_success_at')->nullable()->after('last_parsed_at')->index();
            $table->timestamp('last_attempt_at')->nullable()->after('last_success_at')->index();
            $table->timestamp('next_check_at')->nullable()->after('last_attempt_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn([
                'domain',
                'host',
                'usage_state',
                'health_score',
                'health_state',
                'consecutive_failures',
                'last_success_at',
                'last_attempt_at',
                'next_check_at',
            ]);
        });
    }
};
