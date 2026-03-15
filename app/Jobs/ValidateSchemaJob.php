<?php

namespace App\Jobs;

use App\Domain\Parsing\Contracts\SchemaValidator;
use App\Models\ParserSchema;
use App\Models\Source;
use App\Support\ParseAttemptTracker;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ValidateSchemaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    /**
     * Execute the job.
     */
    public function handle(
        SchemaValidator $schemaValidator,
        ParseAttemptTracker $attemptTracker,
    ): void {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $schema = $this->resolveSchema($source);

        if ($schema === null) {
            return;
        }

        $attempt = $attemptTracker->start(
            source: $source,
            stage: 'schema_validate',
            context: $this->context,
            schema: $schema,
        );

        $payload = (array) ($schema->schema_payload ?? []);
        $validation = $schemaValidator->validate($payload);

        if (! $validation->valid) {
            $attemptTracker->markFailure(
                attempt: $attempt,
                errorType: 'schema_shape_invalid',
                errorMessage: implode(' ', $validation->errors),
                extra: [
                    'schema_id' => $schema->id,
                ],
            );

            $schema->update([
                'validation_score' => 0,
                'last_validated_at' => now(),
                'schema_payload' => array_merge($payload, [
                    'validation_errors' => $validation->errors,
                    'shadow_success_runs' => 0,
                ]),
            ]);

            return;
        }

        $snapshots = $source->snapshots()
            ->whereNotNull('html_snapshot')
            ->orderByDesc('captured_at')
            ->limit(max(1, (int) config('ingestion.schema_validation_snapshot_limit', 3)))
            ->get();

        if ($snapshots->isEmpty()) {
            $attemptTracker->markSkipped($attempt, 'No source snapshots available for schema validation.');

            return;
        }

        $scores = [];
        $snapshotResults = [];

        foreach ($snapshots as $snapshot) {
            $result = $this->evaluateSchemaOnSnapshot(
                schemaPayload: $payload,
                html: (string) $snapshot->html_snapshot,
                baseUrl: (string) ($snapshot->final_url ?: $source->canonical_url ?: $source->source_url),
            );

            $snapshotResults[] = array_merge($result, [
                'snapshot_id' => $snapshot->id,
            ]);
            $scores[] = $result['score'];
        }

        $avgScore = $scores !== [] ? (int) round(array_sum($scores) / count($scores)) : 0;
        $activateThreshold = max(0, min(100, (int) config('ingestion.schema_validation_activate_score', 70)));
        $requiredShadowRuns = max(1, (int) config('ingestion.schema_shadow_success_runs_to_activate', 2));
        $shadowSuccessRuns = (int) ($payload['shadow_success_runs'] ?? 0);

        if ($avgScore >= $activateThreshold) {
            $shadowSuccessRuns++;
        } else {
            $shadowSuccessRuns = 0;
        }

        $updatedPayload = array_merge($payload, [
            'shadow_success_runs' => $shadowSuccessRuns,
            'shadow_last_validated_at' => now()->toIso8601String(),
            'latest_snapshot_results' => $snapshotResults,
            'latest_avg_score' => $avgScore,
        ]);

        $schema->update([
            'validation_score' => $avgScore,
            'last_validated_at' => now(),
            'schema_payload' => $updatedPayload,
        ]);

        if ($avgScore >= $activateThreshold && $shadowSuccessRuns >= $requiredShadowRuns) {
            $source->parserSchemas()
                ->where('id', '!=', $schema->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                ]);

            $schema->update([
                'is_active' => true,
                'is_shadow' => false,
            ]);

            $source->update([
                'status' => 'active',
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'schema_validation' => [
                        'status' => 'activated',
                        'schema_id' => $schema->id,
                        'validation_score' => $avgScore,
                        'shadow_success_runs' => $shadowSuccessRuns,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);
        } else {
            $source->update([
                'status' => 'repairing',
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'schema_validation' => [
                        'status' => 'shadow',
                        'schema_id' => $schema->id,
                        'validation_score' => $avgScore,
                        'shadow_success_runs' => $shadowSuccessRuns,
                        'required_shadow_runs' => $requiredShadowRuns,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);
        }

        $attemptTracker->markSuccess($attempt, [
            'schema_id' => $schema->id,
            'validation_score' => $avgScore,
            'shadow_success_runs' => $shadowSuccessRuns,
            'activated' => $schema->is_active,
        ]);
    }

    private function resolveSchema(Source $source): ?ParserSchema
    {
        $schemaId = $this->context['parser_schema_id'] ?? null;

        if (is_numeric($schemaId)) {
            return ParserSchema::query()
                ->where('source_id', $source->id)
                ->whereKey((int) $schemaId)
                ->first();
        }

        return $source->parserSchemas()
            ->where('is_shadow', true)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @return array{score:int,item_count:int,valid_items:int}
     */
    private function evaluateSchemaOnSnapshot(array $schemaPayload, string $html, string $baseUrl): array
    {
        $dom = new DOMDocument;
        $loaded = @$dom->loadHTML($html);

        if (! $loaded) {
            return [
                'score' => 0,
                'item_count' => 0,
                'valid_items' => 0,
            ];
        }

        $xpath = new DOMXPath($dom);
        $articleXPath = (string) ($schemaPayload['article_xpath'] ?? $schemaPayload['article_selector'] ?? '');
        $titleXPath = (string) ($schemaPayload['title_xpath'] ?? $schemaPayload['title_selector'] ?? '');
        $linkXPath = (string) ($schemaPayload['link_xpath'] ?? $schemaPayload['link_selector'] ?? '');

        if ($articleXPath === '' || $titleXPath === '' || $linkXPath === '') {
            return [
                'score' => 0,
                'item_count' => 0,
                'valid_items' => 0,
            ];
        }

        try {
            $nodes = $xpath->query($articleXPath);
        } catch (Throwable) {
            return [
                'score' => 0,
                'item_count' => 0,
                'valid_items' => 0,
            ];
        }

        if ($nodes === false || $nodes->length === 0) {
            return [
                'score' => 0,
                'item_count' => 0,
                'valid_items' => 0,
            ];
        }

        $validItems = 0;

        foreach ($nodes as $node) {
            $title = $this->extractXPathText($xpath, $titleXPath, $node);
            $link = $this->extractXPathText($xpath, $linkXPath, $node);

            if ($title === '' || $link === '') {
                continue;
            }

            $validItems++;
        }

        $itemCount = $nodes->length;
        $minItems = max(1, (int) config('ingestion.schema_validation_min_items', 3));
        $coverage = $itemCount > 0 ? ($validItems / $itemCount) : 0.0;
        $coverageScore = (int) round($coverage * 70);
        $itemScore = $validItems >= $minItems
            ? min(30, $validItems * 5)
            : (int) round(($validItems / $minItems) * 30);
        $score = max(0, min(100, $coverageScore + $itemScore));

        return [
            'score' => $score,
            'item_count' => $itemCount,
            'valid_items' => $validItems,
        ];
    }

    private function extractXPathText(DOMXPath $xpath, string $expression, DOMNode $context): string
    {
        try {
            $nodes = $xpath->query($expression, $context);
        } catch (Throwable) {
            return '';
        }

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        $first = $nodes->item(0);

        if ($first === null) {
            return '';
        }

        $raw = trim((string) ($first->nodeValue ?? ''));

        return preg_replace('/\s+/u', ' ', $raw) ?: '';
    }
}
