<?php

namespace App\Domain\Parsing\Services;

use App\Domain\Parsing\Contracts\AiSchemaResolver;

class NullAiSchemaResolver implements AiSchemaResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(string $html, string $sourceUrl): array
    {
        return [
            'strategy' => 'ai_disabled',
            'source_url' => $sourceUrl,
            'valid' => false,
        ];
    }
}
