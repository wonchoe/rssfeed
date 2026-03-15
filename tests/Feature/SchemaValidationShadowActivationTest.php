<?php

namespace Tests\Feature;

use App\Jobs\ValidateSchemaJob;
use App\Models\ParserSchema;
use App\Models\Source;
use App\Models\SourceSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaValidationShadowActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shadow_schema_is_activated_after_successful_validation_runs(): void
    {
        config()->set('ingestion.schema_shadow_success_runs_to_activate', 2);
        config()->set('ingestion.schema_validation_activate_score', 60);
        config()->set('ingestion.schema_validation_min_items', 1);

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/news'),
            'source_url' => 'https://example.com/news',
            'source_type' => 'html',
            'status' => 'repairing',
            'usage_state' => 'active',
            'health_score' => 40,
            'health_state' => 'repairing',
            'polling_interval_minutes' => 30,
        ]);

        $schema = ParserSchema::query()->create([
            'source_id' => $source->id,
            'version' => 1,
            'strategy_type' => 'ai_xpath_schema',
            'schema_payload' => [
                'article_xpath' => '//article[.//a[@href]]',
                'title_xpath' => './/h2',
                'link_xpath' => './/a/@href',
                'summary_xpath' => './/p',
                'shadow_success_runs' => 0,
            ],
            'created_by' => 'ai_generated',
            'is_active' => false,
            'is_shadow' => true,
        ]);

        SourceSnapshot::query()->create([
            'source_id' => $source->id,
            'snapshot_kind' => 'parse_input',
            'html_snapshot' => '<html><body><article><h2>Title A</h2><a href="/a">Read</a><p>Summary A</p></article></body></html>',
            'final_url' => 'https://example.com/news',
            'captured_at' => now()->subMinute(),
        ]);

        SourceSnapshot::query()->create([
            'source_id' => $source->id,
            'snapshot_kind' => 'parse_input',
            'html_snapshot' => '<html><body><article><h2>Title B</h2><a href="/b">Read</a><p>Summary B</p></article></body></html>',
            'final_url' => 'https://example.com/news',
            'captured_at' => now(),
        ]);

        ValidateSchemaJob::dispatchSync((string) $source->id, [
            'parser_schema_id' => $schema->id,
            'trigger' => 'test_shadow_1',
        ]);

        $this->assertDatabaseHas('parser_schemas', [
            'id' => $schema->id,
            'is_active' => 0,
            'is_shadow' => 1,
        ]);

        ValidateSchemaJob::dispatchSync((string) $source->id, [
            'parser_schema_id' => $schema->id,
            'trigger' => 'test_shadow_2',
        ]);

        $this->assertDatabaseHas('parser_schemas', [
            'id' => $schema->id,
            'is_active' => 1,
            'is_shadow' => 0,
        ]);
    }
}
