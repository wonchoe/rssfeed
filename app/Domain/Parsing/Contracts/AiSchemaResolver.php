<?php

namespace App\Domain\Parsing\Contracts;

interface AiSchemaResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(string $html, string $sourceUrl): array;
}
