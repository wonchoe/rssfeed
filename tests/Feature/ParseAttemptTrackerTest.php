<?php

namespace Tests\Feature;

use App\Models\ParseAttempt;
use App\Models\Source;
use App\Support\ParseAttemptTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParseAttemptTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_snapshot_sanitizes_invalid_utf8_payload(): void
    {
        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'source_url' => 'https://example.com/feed.xml',
        ]);

        $attempt = ParseAttempt::query()->create([
            'source_id' => $source->id,
            'stage' => 'generate_preview',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $tracker = app(ParseAttemptTracker::class);
        $payload = "Valid prefix \xD1 invalid byte";

        $snapshot = $tracker->captureSnapshot($attempt, $payload, snapshotKind: 'preview_payload');

        $this->assertNotNull($snapshot);
        $this->assertSame(1, preg_match('//u', (string) $snapshot->html_snapshot));
        $this->assertStringContainsString('Valid prefix', (string) $snapshot->html_snapshot);

        $attempt->refresh();
        $this->assertNotNull($attempt->snapshot_reference);
    }

    public function test_mark_failure_truncates_large_error_message(): void
    {
        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/large-error-feed.xml'),
            'source_url' => 'https://example.com/large-error-feed.xml',
        ]);

        $attempt = ParseAttempt::query()->create([
            'source_id' => $source->id,
            'stage' => 'generate_preview',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $tracker = app(ParseAttemptTracker::class);
        $message = str_repeat('nested sql error ', 800);

        $tracker->markFailure($attempt, 'generate_preview_exception', $message);

        $attempt->refresh();

        $this->assertSame('failed', $attempt->status);
        $this->assertNotNull($attempt->error_message);
        $this->assertLessThanOrEqual(4000, strlen((string) $attempt->error_message));
        $this->assertStringStartsWith('nested sql error', (string) $attempt->error_message);
    }
}
