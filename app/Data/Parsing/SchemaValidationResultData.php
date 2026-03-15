<?php

namespace App\Data\Parsing;

readonly class SchemaValidationResultData
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
    ) {}
}
