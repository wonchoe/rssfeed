<?php

namespace App\Support;

use App\Models\ParserSchema;
use App\Models\Source;

class ParserSchemaRegistry
{
    /**
     * @param  array<string, mixed>  $schemaPayload
     */
    public function ensureActiveFeedSchema(
        Source $source,
        string $sourceType,
        ?float $confidence = null,
        array $schemaPayload = [],
    ): ParserSchema {
        $strategyType = $this->strategyType($sourceType);
        $active = $source->activeParserSchema()->first();
        $confidenceScore = $confidence !== null ? round($confidence * 100, 2) : null;

        if ($active !== null && $active->strategy_type === $strategyType) {
            $active->update([
                'schema_payload' => $schemaPayload !== [] ? $schemaPayload : $active->schema_payload,
                'confidence_score' => $confidenceScore ?? $active->confidence_score,
                'validation_score' => $active->validation_score ?? $confidenceScore,
                'last_validated_at' => now(),
                'is_active' => true,
                'is_shadow' => false,
            ]);

            return $active->refresh();
        }

        if ($active !== null) {
            $active->update([
                'is_active' => false,
            ]);
        }

        $nextVersion = ((int) $source->parserSchemas()->max('version')) + 1;

        return ParserSchema::query()->create([
            'source_id' => $source->id,
            'version' => max(1, $nextVersion),
            'strategy_type' => $strategyType,
            'schema_payload' => $schemaPayload !== [] ? $schemaPayload : [
                'source_type' => $sourceType,
                'pipeline' => 'deterministic_feed',
                'required_fields' => ['title', 'url'],
                'optional_fields' => ['summary', 'published_at', 'image_url'],
            ],
            'confidence_score' => $confidenceScore,
            'validation_score' => $confidenceScore,
            'created_by' => 'rule_based',
            'is_active' => true,
            'is_shadow' => false,
            'last_validated_at' => now(),
        ]);
    }

    public function activeSchemaFor(Source $source): ?ParserSchema
    {
        return $source->activeParserSchema()->first();
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     */
    public function activateCustomSchema(
        Source $source,
        string $strategyType,
        array $schemaPayload,
        ?float $confidence = null,
        string $createdBy = 'manual',
    ): ParserSchema {
        $active = $source->activeParserSchema()->first();
        $confidenceScore = $confidence !== null ? round($confidence * 100, 2) : null;

        if ($active !== null) {
            $active->update([
                'is_active' => false,
            ]);
        }

        $nextVersion = ((int) $source->parserSchemas()->max('version')) + 1;

        return ParserSchema::query()->create([
            'source_id' => $source->id,
            'version' => max(1, $nextVersion),
            'strategy_type' => $strategyType,
            'schema_payload' => $schemaPayload,
            'confidence_score' => $confidenceScore,
            'validation_score' => $confidenceScore,
            'created_by' => $createdBy,
            'is_active' => true,
            'is_shadow' => false,
            'last_validated_at' => now(),
        ]);
    }

    private function strategyType(string $sourceType): string
    {
        return match ($sourceType) {
            'rss' => 'deterministic_rss',
            'atom' => 'deterministic_atom',
            'json_feed' => 'deterministic_json_feed',
            default => 'deterministic_feed',
        };
    }
}
