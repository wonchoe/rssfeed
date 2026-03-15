<?php

namespace App\Domain\Parsing\Services;

use App\Data\Parsing\SchemaValidationResultData;
use App\Domain\Parsing\Contracts\SchemaValidator;

class BasicSchemaValidator implements SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function validate(array $schema): SchemaValidationResultData
    {
        $errors = [];

        $articleSelector = $schema['article_xpath'] ?? $schema['article_selector'] ?? null;
        $titleSelector = $schema['title_xpath'] ?? $schema['title_selector'] ?? null;
        $linkSelector = $schema['link_xpath'] ?? $schema['link_selector'] ?? null;

        if (! is_string($articleSelector) || trim($articleSelector) === '') {
            $errors[] = 'Missing or invalid article_xpath/article_selector.';
        }

        if (! is_string($titleSelector) || trim($titleSelector) === '') {
            $errors[] = 'Missing or invalid title_xpath/title_selector.';
        }

        if (! is_string($linkSelector) || trim($linkSelector) === '') {
            $errors[] = 'Missing or invalid link_xpath/link_selector.';
        }

        return new SchemaValidationResultData(
            valid: $errors === [],
            errors: $errors,
        );
    }
}
