<?php

namespace App\Domain\Parsing\Contracts;

use App\Data\Parsing\SchemaValidationResultData;

interface SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function validate(array $schema): SchemaValidationResultData;
}
