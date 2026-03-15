<?php

namespace App\Data\Parsing;

readonly class HtmlCandidateData
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $selector,
        public string $url,
        public ?string $title = null,
        public array $attributes = [],
    ) {}
}
